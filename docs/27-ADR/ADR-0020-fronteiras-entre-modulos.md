# ADR-0020: Fronteiras entre módulos, e onde vivem migrations e testes

> **Status:** ✅ **Aceito** · **Data:** 2026-07-22 · **Decisores:** chief-architect
> **Complementa:** [ADR-0001](ADR-0001-monolito-modular.md) (monolito modular), [ADR-0015](ADR-0015-camadas-e-repositorios.md) (camadas)
> **Módulos afetados:** 05, 22, 04, 02

## Contexto

O [ADR-0001](ADR-0001-monolito-modular.md) decidiu monolito modular e a [pasta 05 §2](../05-Backend/README.md) já fixou a estrutura física: `app/Modules/<Módulo>/`, autoload PSR-4 sob o namespace `App\` já existente, service providers próprios, sem pacote externo de "module system". Isso está resolvido e não se reabre aqui.

Três pontos, porém, seguem em aberto — e todos ficam **caros de mudar depois do primeiro módulo escrito**:

1. **Não há regra escrita sobre o que um módulo pode enxergar do outro.** A pasta 05 §7 apenas registra o risco ("módulos importarem uns aos outros livremente") e propõe `deptrac` "na Fase 2". Adiar a fronteira para depois de escrever seis módulos é adiar para quando o estrago já existe.
2. **Testes:** a pasta 05 §2 coloca `Tests/` dentro de cada módulo. O Pest e o `php artisan test` assumem `tests/`. A divergência exige configuração e atrapalha cobertura, CI e IDE.
3. **Migrations:** a pasta 05 §2 as coloca dentro de cada módulo. Isso é viável, mas tem uma implicação de ordenação que não estava documentada.

O contexto que pesa na dose: **um desenvolvedor**, com o risco dominante do projeto sendo não terminar ([R6](../00-Visao-Geral/04-analise-critica-gate00.md)). Fronteira que só existe em documento não sobrevive a uma sexta-feira difícil; fronteira que quebra o CI, sim.

## Decisão

### 1. Fronteiras entre módulos

**Superfície pública de um módulo = seus `Services/` e seus `Events/`.** Mais nada.

| De um módulo, outro módulo pode… | Permitido |
|---|---|
| Chamar um `Service` (caso de uso nomeado) | ✅ |
| Ouvir/disparar um `Event` de domínio | ✅ (forma preferida) |
| Usar uma `Interface` publicada pelo módulo (ex.: `NfeGatewayInterface`) | ✅ |
| Referenciar um `Model` de outro módulo | ❌ |
| Usar `Repositories/`, `Jobs/`, `Http/` ou `Policies/` de outro módulo | ❌ |
| Consultar diretamente as tabelas de outro módulo | ❌ |

Regras complementares:

- **Acoplamento preferencial é por evento** ([pasta 02](../02-Dominio/01-eventos-de-dominio.md)). Chamada direta de Service só quando a operação precisa ser síncrona e transacional.
- **Dependência circular entre módulos é proibida.** Se A precisa de B e B precisa de A, ou o acoplamento vira evento, ou o conceito compartilhado sobe para `app/Support/`.
- **`Support/` é o núcleo compartilhado**: value objects (`Money`, `Sku`, `Quantity`), exceção base de domínio, helpers coesos. Não tem regra de negócio de módulo nenhum e **não depende de módulo algum**.
- **`Integrations` pode depender dos Services dos outros módulos; nenhum módulo depende de `Integrations`** — módulos falam com interfaces (ADR-0009, ADR-0018), e o adapter concreto é injetado.

### 2. A fronteira é verificada por teste, não por boa vontade

**Adotamos testes de arquitetura do Pest** (`arch()`), executados no CI como qualquer outro teste:

```php
arch('módulos não acessam models de outros módulos')
    ->expect('App\Modules\Sales')
    ->not->toUse('App\Modules\Catalog\Models');

arch('Support não conhece módulo algum')
    ->expect('App\Support')
    ->not->toUse('App\Modules');
```

Isso substitui o `deptrac` "da Fase 2": custa alguns arquivos de teste, roda desde o primeiro módulo e falha **no momento em que a violação é escrita** — não seis meses depois. `deptrac` continua disponível se a checagem precisar ficar mais fina.

### 3. Testes ficam em `tests/`, espelhando os módulos

Revoga o `Tests/` por módulo da [pasta 05 §2](../05-Backend/README.md).

```text
tests/
├── Feature/<Módulo>/        # fluxos, HTTP, Inertia (assertInertia)
├── Unit/<Módulo>/           # domínio puro
└── Architecture/            # os arch() da decisão 2
```

Motivo: `php artisan test`, Pest, cobertura, relatórios de CI e integração de IDE assumem `tests/`. Manter a convenção elimina configuração e ambiguidade sem perder navegabilidade — o espelhamento por nome de módulo dá a mesma orientação.

### 4. Migrations ficam **centralizadas** em `database/migrations/`

Revoga o `Database/migrations` por módulo da [pasta 05 §2](../05-Backend/README.md).

Motivo determinante: a ordem de execução das migrations é **global**, definida pelo timestamp no nome do arquivo — independentemente da pasta. Chaves estrangeiras entre módulos (`order_items.product_id` → `products.id`) dependem dessa ordem. Espalhar os arquivos por nove pastas **esconde a única coisa que importa** para depurar um erro de ordenação, sem trazer isolamento real: o banco é um só ([ADR-0002](ADR-0002-mariadb.md)) e o esquema é descrito de forma unificada na [pasta 04](../04-Banco-de-Dados/01-modelo-conceitual.md).

`factories/` e `seeders/` seguem a convenção padrão do Laravel (`database/factories`, `database/seeders`), com subpastas por módulo.

### 5. Registro do módulo

Cada módulo tem `<Módulo>ServiceProvider`, declarado em `bootstrap/providers.php`, responsável por: carregar rotas (`Routes/web.php` e, quando houver, `Routes/api.php`), registrar policies, listeners e bindings de interface. Nada de descoberta automática por convenção mágica.

## Alternativas consideradas

### Alternativa A — Manter a fronteira só como norma escrita e revisão de PR
**Prós:** custo zero de setup.
**Contras:** com um único desenvolvedor, "revisão de PR" é a mesma pessoa que escreveu o código, cansada, na sexta-feira. Norma sem verificação é norma que erode.
**Descartada:** o custo de escrever cinco `arch()` é de minutos.

### Alternativa B — `deptrac` desde já
**Prós:** análise de dependência mais rica, camadas configuráveis.
**Contras:** mais uma dependência, arquivo de configuração próprio e um relatório separado do resto dos testes.
**Descartada por ora:** o Pest já estará instalado e o `arch()` cobre a regra que temos. `deptrac` volta à mesa se a checagem precisar de granularidade que o Pest não dê.

### Alternativa C — Migrations por módulo (o que a pasta 05 dizia)
**Prós:** módulo autocontido; permitiria extrair um módulo com seu esquema.
**Contras:** a ordenação continua global e passa a ser invisível; `make:migration` exige `--path` sempre; a extração de módulo é hipótese remota que o próprio ADR-0001 condiciona a gatilho.
**Descartada:** paga-se opacidade diária por um benefício que talvez nunca se realize.

### Alternativa D — Testes por módulo (o que a pasta 05 dizia)
**Prós:** coesão do módulo.
**Contras:** exige testsuites customizados no `phpunit.xml`, atrapalha cobertura agregada e diverge do que toda ferramenta espera.
**Descartada:** o espelhamento em `tests/` entrega a mesma coesão sem atrito.

## Consequências

**Positivas:**
- A fronteira entre contextos deixa de ser intenção e passa a ser fato verificado no CI.
- Um módulo pode ser lido isoladamente: `app/Modules/Production/` contém tudo que decide produção.
- Ordem de migrations volta a ser legível numa única listagem — o que importa quando uma FK falha.
- Zero configuração extra de teste; `php artisan test` funciona de fábrica.
- Compatível com o autoload padrão: `app/Modules/Catalog/Models/Product.php` já resolve para `App\Modules\Catalog\Models\Product`, sem tocar no `composer.json`.

**Negativas / dívidas assumidas:**
- A pasta 05 §2 precisa ser corrigida em dois pontos (testes e migrations) — feito junto com este ADR.
- `arch()` verifica dependência entre namespaces, não semântica: um Service que devolve um Model de outro módulo por acidente passa no teste. A revisão humana continua necessária no que é sutil.
- Acoplamento por evento é mais difícil de rastrear que chamada direta — o preço do desacoplamento. Mitigado pelo catálogo de eventos da [pasta 02](../02-Dominio/01-eventos-de-dominio.md), que é obrigatório manter atualizado.
- Migrations centralizadas tornam o módulo não-extraível sem trabalho manual. Aceito: a extração exige gatilho objetivo (ADR-0001) e, quando ocorrer, mover migrations é o menor dos problemas.

**Gatilhos de revisão:**
- Um segundo desenvolvedor entrar e as regras de fronteira gerarem atrito recorrente → reavaliar granularidade.
- Número de módulos passar de ~12, ou algum módulo permanecer com menos de três classes por um ano → consolidar (fronteira demais também é custo).
- `arch()` mostrar-se insuficiente para uma regra que importe → adotar `deptrac`.
- Extração real de um módulo para serviço próprio (gatilho do ADR-0001) → migrations e testes voltam à discussão.
