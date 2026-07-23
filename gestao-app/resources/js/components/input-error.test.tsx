import InputError from '@/components/input-error';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

// Este componente carrega toda a devolutiva de validação da UI do ERP:
// se ele engolir uma mensagem, o usuário vê o formulário recusar sem
// dizer por quê. Ver docs/06-Frontend §formulários.
describe('InputError', () => {
    it('exibe a mensagem de erro quando existe', () => {
        render(<InputError message="O CNPJ informado é inválido." />);

        expect(screen.getByText('O CNPJ informado é inválido.')).toBeInTheDocument();
    });

    it('não renderiza nada quando não há mensagem', () => {
        const { container } = render(<InputError />);

        expect(container).toBeEmptyDOMElement();
    });

    it('não renderiza nada quando a mensagem é string vazia', () => {
        const { container } = render(<InputError message="" />);

        expect(container).toBeEmptyDOMElement();
    });

    it('mantém as classes próprias ao receber className', () => {
        render(<InputError message="Campo obrigatório." className="mt-4" />);

        const paragrafo = screen.getByText('Campo obrigatório.');

        expect(paragrafo).toHaveClass('mt-4');
        expect(paragrafo).toHaveClass('text-sm');
    });
});
