import { SVGAttributes } from 'react';

/**
 * O selo da Dona Arteira: as gotas de tinta do logo, nas cores exatas
 * dele — rosa #FF66C4, azul #38B6F0, verde #7ED957, amarelo #FAC641.
 *
 * Vetorial e com `fill` explícito por gota de propósito: nasce colorido
 * em qualquer tamanho e ignora o `fill-current`/`text-*` que os layouts
 * de login herdam do starter kit — cor de path vence cor herdada do svg,
 * então o mesmo componente serve a barra lateral e a tela de entrada sem
 * virar um borrão de uma cor só.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    // Uma gota: ponta em cima na origem, base arredondada embaixo.
    const gota = 'M0 0 C 5.5 6.5, 6.5 12, 0 20 C -6.5 12, -5.5 6.5, 0 0 Z';

    return (
        <svg {...props} viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
            <path d={gota} fill="#7ED957" transform="translate(13 14) rotate(-34) scale(0.9)" />
            <path d={gota} fill="#38B6F0" transform="translate(34 12) rotate(24) scale(0.95)" />
            <path d={gota} fill="#FF66C4" transform="translate(21 5) rotate(-8) scale(1.18)" />
            <path d={gota} fill="#FAC641" transform="translate(30 6) rotate(14) scale(0.5)" />
        </svg>
    );
}
