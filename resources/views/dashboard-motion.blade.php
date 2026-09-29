{{--
    Animação de entrada dos números dos dashboards ("count-up sutil,
    sem exagero").

    DECISÕES:
    - PROGRESSIVE ENHANCEMENT: o número correto já vem renderizado pelo
      servidor, formatado no idioma do usuário. Este script só o reconta a
      partir de zero na entrada. Sem JS, ou com JS falhando, a tela continua
      certa — nunca fica um "0" pendurado.
    - RESPEITA prefers-reduced-motion: quem pediu menos movimento não recebe
      nenhum. A verificação é feita a cada rodada, não uma vez no load, porque
      a preferência do sistema pode mudar com a sessão aberta.
    - Não reformata nada: anima os DÍGITOS que já estão no texto, preservando
      prefixos, sufixos ("%", "ms", "MB") e os separadores do locale. Formatar
      de novo no cliente seria uma segunda fonte da verdade de formatação.
    - Reage ao Livewire: trocar o período redesenha os widgets, e cada valor
      novo é contado outra vez (um MutationObserver cuida disso, sem depender
      de nome de evento interno do Livewire).
    - Inline por decisão documentada: a CSP do /admin já permite
      'unsafe-inline' em script-src (ver config/security.php) e este trecho é
      pequeno o bastante para não pagar um request e uma entrada no manifest.
--}}
<script>
    (() => {
        const DURACAO = 650;

        const reduzMovimento = () =>
            window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        // Extrai o primeiro número do texto, com os separadores do locale.
        const partes = (texto) => {
            const casado = texto.match(/-?\d[\d.,\u00a0\u202f]*/);

            if (! casado) {
                return null;
            }

            const bruto = casado[0];
            const digitos = bruto.replace(/[^\d]/g, '');

            if (digitos === '') {
                return null;
            }

            return {
                prefixo: texto.slice(0, casado.index),
                sufixo: texto.slice(casado.index + bruto.length),
                molde: bruto,
                alvo: parseInt(digitos, 10),
            };
        };

        // Reescreve o molde ("1.234,5") com os dígitos do valor atual,
        // preservando a pontuação exatamente onde ela está.
        const comDigitos = (molde, valor, total) => {
            const alvo = String(total).padStart(1, '0');
            const atual = String(valor).padStart(alvo.length, '0');
            let i = 0;

            return molde.replace(/\d/g, () => atual[i++] ?? '0');
        };

        const animar = (elemento) => {
            const texto = elemento.textContent.trim();
            const info = partes(texto);

            if (! info || info.alvo === 0) {
                return;
            }

            const inicio = performance.now();

            const passo = (agora) => {
                const progresso = Math.min(1, (agora - inicio) / DURACAO);
                // easeOutCubic: rápido no começo, assenta no fim.
                const suave = 1 - Math.pow(1 - progresso, 3);
                const valor = Math.round(info.alvo * suave);

                elemento.textContent =
                    info.prefixo + comDigitos(info.molde, valor, info.alvo) + info.sufixo;

                if (progresso < 1) {
                    requestAnimationFrame(passo);
                } else {
                    elemento.textContent = texto;
                }
            };

            requestAnimationFrame(passo);
        };

        const varrer = () => {
            if (reduzMovimento()) {
                return;
            }

            document
                .querySelectorAll('.fi-wi-stats-overview-stat-value:not([data-dash-counted])')
                .forEach((elemento) => {
                    elemento.dataset.dashCounted = '1';
                    animar(elemento);
                });
        };

        const iniciar = () => {
            varrer();

            new MutationObserver(() => varrer()).observe(document.body, {
                childList: true,
                subtree: true,
            });
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', iniciar);
        } else {
            iniciar();
        }
    })();
</script>
