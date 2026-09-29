{{--
    Widget de PROGRESSO até uma meta (variante "Crescimento & API").

    É o exemplo de widget CUSTOM do kit: quando o formato não é card, gráfico
    nem tabela, herda-se Filament\Widgets\Widget e desenha-se aqui. Continua
    dentro da mesma <x-filament::section> dos outros widgets, com o mesmo raio
    de borda e a mesma escala tipográfica — consistência de grade é o que
    separa um painel premium de uma colagem de componentes.

    A barra tem `aria-valuenow`: leitor de tela lê o progresso; quem enxerga
    lê o mesmo número ao lado, em tabular-nums.
--}}
<x-filament-widgets::widget class="fi-dash-goal">
    <x-filament::section :heading="$heading" :description="$description">
        <div class="fi-dash-goal-body">
            <p class="fi-dash-goal-value">
                <span class="fi-dash-number">{{ $achieved }}</span>
                <span class="fi-dash-goal-target">/ {{ $goal }}</span>
            </p>

            <div
                class="fi-dash-goal-track"
                role="progressbar"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow="{{ $progress }}"
                aria-label="{{ $heading }}"
            >
                <div
                    class="fi-dash-goal-bar"
                    style="width: {{ $progress }}%; background-color: {{ $stroke }};"
                ></div>

                {{-- Marca do RITMO esperado: sem ela, uma barra pela metade no
                     dia 3 e no dia 28 pareceriam a mesma notícia. --}}
                <span class="fi-dash-goal-pace" style="inset-inline-start: {{ $pace }}%;"></span>
            </div>

            <div class="fi-dash-goal-footer">
                <x-filament::badge :color="$status">{{ $statusLabel }}</x-filament::badge>

                <span class="fi-dash-goal-meta">
                    <span class="fi-dash-number">{{ $progressLabel }}</span> · {{ $paceLabel }}
                </span>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
