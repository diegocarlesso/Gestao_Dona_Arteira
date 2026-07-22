# 05 — Imagens / Mídia

> **Status:** Em revisão · **Última atualização:** 2026-07-06 · **Responsável:** woocommerce-specialist
> **ADRs relacionados:** ADR-0017 (mídia canônica)

## 1. Objetivo

Inventariar a mídia (anexos) do site — volume, tipos, integridade referencial e limitações — para embasar a estratégia de migração de imagens ([ADR-0017](../27-ADR/ADR-0017-midia-canonica.md)).

## 2. Volume e tipos

| Tipo MIME | Quantidade |
|---|---:|
| image/jpeg | 891 |
| image/png | 108 |
| image/heic | 1 |
| application/zip | 2 |
| **Total de anexos** | **1.002** |

- **~999 imagens** + 1 HEIC + 2 ZIP.
- O **HEIC** (foto de iPhone) é um formato **não renderizável nativamente** por muitos navegadores/bibliotecas — candidato a conversão. Os 2 **ZIP** são anexos não-imagem (provável artefato de import/backup) — investigar/descartar.

## 3. Integridade referencial (no banco)

| Verificação | Resultado |
|---|---:|
| Produtos com imagem destaque órfã (aponta para anexo inexistente) | **0** 🟢 |
| Arquivos duplicados (`_wp_attached_file` repetido) | **0** 🟢 |
| Produtos sem imagem destaque | **0** 🟢 |
| Produtos sem qualquer imagem (destaque **e** galeria) | **0** 🟢 |
| Anexos com `_wp_attached_file` | 1.002 |
| Anexos com `_wp_attachment_metadata` | 1.000 |

**Todos os produtos têm imagem** e **todas as referências de destaque apontam para anexos existentes** — integridade referencial **boa dentro do banco**.

## 4. Limitação crítica: arquivos físicos

⚠️ **Um dump SQL não contém o filesystem.** As imagens reais vivem em `https://donaarteira.com.br/wp-content/uploads/...` (e, no desktop, em FTP sob `pecas/{code}/`). Portanto:

- **Não é possível, só com o dump, confirmar que os 1.002 arquivos existem, seu peso total, resolução, ou se há arquivos ausentes/quebrados no servidor.**
- As verificações acima são de **consistência de referência no banco**, não de existência do arquivo.

Para inventário físico completo (peso total, faltantes, quebrados) será preciso **acesso ao `/uploads` do WordPress e ao FTP do desktop** — ver [98](98-perguntas-para-o-negocio.md).

## 5. Mídia no sistema desktop (referência)

O desktop guarda imagens de peça em **FTP** (`piece_images.ftp_path`, caminho `pecas/{code}/{arquivo}`), com upload direto pelo formulário de peça. São **dois acervos de imagem distintos** (site e desktop), possivelmente sobrepostos — a migração precisa definir a **fonte canônica** ([ADR-0017](../27-ADR/ADR-0017-midia-canonica.md): fase 1 a mídia fica no Woo).

## 6. Impacto na migração

- **Fase 1** ([ADR-0017](../27-ADR/ADR-0017-midia-canonica.md)): manter mídia no Woo, ERP referencia por URL — evita mover o acervo (~1.000 arquivos; peso total a confirmar) no cutover.
- Antes de qualquer migração de mídia: **auditar o `/uploads` físico** (peso, faltantes), **converter o HEIC**, **remover os ZIP**.
- Consolidar acervos site × FTP-desktop após definir a fonte canônica.

## 7. Perguntas em aberto

Em [98](98-perguntas-para-o-negocio.md): peso total do `/uploads`? há imagens no desktop que não estão no site? qual acervo é a fonte de verdade das fotos de produto?
