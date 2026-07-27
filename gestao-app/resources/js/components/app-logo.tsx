import AppLogoIcon from './app-logo-icon';

export default function AppLogo() {
    return (
        <>
            {/* Quadrado branco, e não o `bg-sidebar-primary` do starter kit:
                as gotas são coloridas e sumiriam num fundo escuro. O branco
                repete o fundo do logo original e vale nos dois temas. */}
            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-white ring-1 ring-black/5">
                <AppLogoIcon className="size-6" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-none font-semibold">Dona Arteira</span>
            </div>
        </>
    );
}
