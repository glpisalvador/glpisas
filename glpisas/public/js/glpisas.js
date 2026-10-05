/**
 * Plugin GLPI SAS - interações das páginas (formulários, ativar/desativar, excluir, detalhes, multiselect)
 */
(function () {
    'use strict';

    if (window.GlpiSas) {
        window.GlpiSas.iniciar();
        return;
    }

    var raiz = (window.CFG_GLPI && window.CFG_GLPI.root_doc) ? window.CFG_GLPI.root_doc : '';
    var urlAjax = raiz + '/plugins/glpisas/front/ajax.php';

    function token() {
        var m = document.querySelector('meta[property="glpi:csrf_token"]');
        return m ? m.getAttribute('content') : '';
    }

    function guardarToken(t) {
        if (!t) {
            return;
        }
        var m = document.querySelector('meta[property="glpi:csrf_token"]');
        if (m) {
            m.setAttribute('content', t);
        }
    }

    function aviso(texto, erro) {
        if (erro && typeof window.glpi_toast_error === 'function') {
            window.glpi_toast_error(texto);
        } else if (!erro && typeof window.glpi_toast_info === 'function') {
            window.glpi_toast_info(texto);
        }
    }

    function lerJson(texto) {
        try {
            return JSON.parse(texto);
        } catch (e) {
            var m = texto.match(/\{[\s\S]*\}\s*$/);
            if (m) {
                try {
                    return JSON.parse(m[0]);
                } catch (e2) { /* segue */ }
            }
        }
        return { success: false, message: 'Resposta inválida do servidor.' };
    }

    function enviar(dados, metodo) {
        var fd = new FormData();
        Object.keys(dados).forEach(function (k) { fd.append(k, dados[k]); });
        var opcoes = { method: metodo || 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
        var url = urlAjax;
        if (opcoes.method === 'GET') {
            url += '?' + new URLSearchParams(dados).toString();
        } else {
            var t = token();
            if (t) {
                opcoes.headers['X-Glpi-Csrf-Token'] = t;
                fd.append('_glpi_csrf_token', t);
            }
            opcoes.body = fd;
        }
        return fetch(url, opcoes).then(function (r) { return r.text(); }).then(function (txt) {
            var j = lerJson(txt);
            guardarToken(j.new_token);
            return j;
        });
    }

    function esc(t) {
        var d = document.createElement('div');
        d.textContent = t == null ? '' : String(t);
        return d.innerHTML;
    }

    // ------------------------------------------------------------------
    // Entidades: esconde as filhas nos seletores de entidade
    // ------------------------------------------------------------------
    function filtroEntidades() {
        if (!window.jQuery || window.glpisasFiltroEntidades) {
            return;
        }
        window.glpisasFiltroEntidades = true;
        window.jQuery.ajaxPrefilter(function (opcoes) {
            var ocultas = window.glpisasEntidadesOcultas || [];
            if (!ocultas.length || !opcoes.url || opcoes.url.indexOf('getDropdownValue') === -1) {
                return;
            }
            var dados = typeof opcoes.data === 'string' ? opcoes.data : '';
            if (dados.indexOf('itemtype=Entity') === -1 || !document.querySelector('.glpisas')) {
                return;
            }
            var original = opcoes.success;
            opcoes.success = function (resposta) {
                var r = resposta;
                try {
                    if (typeof r === 'string') {
                        r = JSON.parse(r);
                    }
                    var filtrar = function (lista) {
                        return lista.filter(function (it) {
                            if (it.children) {
                                it.children = filtrar(it.children);
                                return it.children.length > 0;
                            }
                            return ocultas.indexOf(parseInt(it.id, 10)) === -1;
                        });
                    };
                    if (r && r.results) {
                        r.results = filtrar(r.results);
                    }
                } catch (e) { r = resposta; }
                if (typeof original === 'function') {
                    return original.apply(this, [r].concat([].slice.call(arguments, 1)));
                }
                return r;
            };
        });
    }

    // ------------------------------------------------------------------
    // Formulários: campos que dependem de outros
    // ------------------------------------------------------------------
    function valor(form, nome) {
        var el = form.querySelector('[name="' + nome + '"]');
        return el ? el.value : '';
    }

    function mostrar(form, seletor, sim) {
        form.querySelectorAll(seletor).forEach(function (el) { el.hidden = !sim; });
    }

    function atualizarFormulario(form) {
        if (form.querySelector('[name="save_action"][value="salvar_limite"]')) {
            var membros = valor(form, 'recurso') === 'membros';
            mostrar(form, '.glpisas-so-periodo', !membros);
            mostrar(form, '.glpisas-so-total', !membros && valor(form, 'periodo') === 'total');
        }
        if (form.querySelector('[name="save_action"][value="salvar_horas"]')) {
            var tipo = valor(form, 'tipo_alvo');
            form.querySelectorAll('.glpisas-alvo').forEach(function (el) { el.hidden = el.getAttribute('data-tipo') !== tipo; });
            var periodo = valor(form, 'periodo');
            mostrar(form, '.glpisas-so-datas', periodo !== 'mes');
            mostrar(form, '.glpisas-so-contrato', periodo === 'contrato');
            mostrar(form, '.glpisas-so-entidade', tipo === 'entidade');
        }
    }

    function formatarMoeda(el) {
        var t = (el.value || '').trim();
        if (t === '') {
            return;
        }
        if (t.indexOf(',') !== -1) {
            t = t.replace(/\./g, '').replace(',', '.');
        }
        var n = parseFloat(t.replace(/[^0-9.]/g, ''));
        if (isNaN(n)) {
            el.value = '';
            return;
        }
        el.value = n.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    // ------------------------------------------------------------------
    // Multiselect (perfis isentos)
    // ------------------------------------------------------------------
    function multiAtualizar(caixa) {
        var itens = Array.prototype.slice.call(caixa.querySelectorAll('.glpisas-multi-item'));
        var marcados = 0;
        itens.forEach(function (it) {
            var c = it.querySelector('input').checked;
            it.classList.toggle('selected', c);
            if (c) {
                marcados++;
            }
        });
        var lista = caixa.querySelector('.glpisas-multi-lista');
        itens.sort(function (a, b) {
            var ca = a.querySelector('input').checked ? 0 : 1;
            var cb = b.querySelector('input').checked ? 0 : 1;
            return ca !== cb ? ca - cb : a.getAttribute('data-label').localeCompare(b.getAttribute('data-label'));
        }).forEach(function (it) { lista.appendChild(it); });
        var visiveis = itens.filter(function (it) { return !it.hidden; });
        var marcadosVisiveis = visiveis.filter(function (it) { return it.querySelector('input').checked; }).length;
        var todos = caixa.querySelector('.glpisas-multi-todos');
        todos.checked = visiveis.length > 0 && marcadosVisiveis === visiveis.length;
        todos.indeterminate = marcadosVisiveis > 0 && marcadosVisiveis < visiveis.length;
        caixa.querySelector('.glpisas-multi-contador').textContent = marcados + ' de ' + itens.length + ' selecionado(s)';
    }

    function multiFiltrar(caixa) {
        var termo = (caixa.querySelector('.glpisas-multi-busca').value || '').toLowerCase().trim();
        caixa.querySelectorAll('.glpisas-multi-item').forEach(function (it) {
            it.hidden = termo !== '' && it.getAttribute('data-label').indexOf(termo) === -1;
        });
        multiAtualizar(caixa);
    }

    // ------------------------------------------------------------------
    // Detalhes das horas (modal)
    // ------------------------------------------------------------------
    function abrirDetalhes(id) {
        var modalEl = document.getElementById('glpisas-modal-detalhes');
        if (!modalEl || !window.bootstrap) {
            return;
        }
        var corpo = modalEl.querySelector('.modal-body');
        corpo.innerHTML = '<div class="glpisas-carregando"><i class="ti ti-loader"></i> Carregando…</div>';
        modalEl.querySelector('.glpisas-modal-csv').setAttribute('href', raiz + '/plugins/glpisas/front/exportar.php?tipo=horas&id=' + encodeURIComponent(id));
        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        enviar({ action: 'detalhes_horas', id: id }, 'GET').then(function (j) {
            if (!j.success) {
                corpo.innerHTML = '<div class="glpisas-alerta glpisas-alerta-erro">' + esc(j.message) + '</div>';
                return;
            }
            var r = j.resumo;
            var html = '<div class="glpisas-detalhe-topo"><strong>' + esc(j.titulo) + '</strong><span>' + esc(j.periodo) + ' · ' + esc(j.fonte) + '</span></div>';
            html += '<div class="glpisas-indicadores glpisas-indicadores-mini">'
                + '<div class="glpisas-indicador"><div><div class="glpisas-indicador-valor">' + esc(r.consumido) + '</div><div class="glpisas-indicador-rotulo">Consumido de ' + esc(r.contratado) + '</div></div></div>'
                + '<div class="glpisas-indicador"><div><div class="glpisas-indicador-valor">' + esc(r.excedente) + '</div><div class="glpisas-indicador-rotulo">Excedente</div></div></div>'
                + '<div class="glpisas-indicador"><div><div class="glpisas-indicador-valor">' + esc(r.valor_dentro) + '</div><div class="glpisas-indicador-rotulo">Dentro do contrato</div></div></div>'
                + '<div class="glpisas-indicador"><div><div class="glpisas-indicador-valor">' + esc(r.valor_excedente) + '</div><div class="glpisas-indicador-rotulo">Excedente</div></div></div>'
                + '<div class="glpisas-indicador"><div><div class="glpisas-indicador-valor">' + esc(r.valor_total) + '</div><div class="glpisas-indicador-rotulo">Total</div></div></div></div>';
            if (!j.chamados.length) {
                html += '<div class="glpisas-vazio"><i class="ti ti-mood-empty"></i> Nenhum chamado consumiu horas no período.</div>';
            } else {
                html += '<div class="table-responsive"><table class="table table-hover table-sm glpisas-lista"><thead><tr><th>Chamado</th><th>Abertura</th><th>Status</th><th>Entidade</th><th class="text-end">Tempo</th></tr></thead><tbody>';
                j.chamados.forEach(function (c) {
                    html += '<tr><td><a href="' + esc(c.link) + '">#' + esc(c.id) + ' ' + esc(c.nome) + '</a></td><td class="text-nowrap">' + esc(c.data) + '</td><td>' + esc(c.status) + '</td><td>' + esc(c.entidade) + '</td><td class="text-end">' + esc(c.tempo) + '</td></tr>';
                });
                html += '</tbody></table></div>';
            }
            corpo.innerHTML = html;
        }).catch(function () {
            corpo.innerHTML = '<div class="glpisas-alerta glpisas-alerta-erro">Não foi possível carregar os chamados.</div>';
        });
    }

    // ------------------------------------------------------------------
    // Eventos (delegados: valem também para conteúdo carregado nas abas)
    // ------------------------------------------------------------------
    function ligarEventos() {
        document.addEventListener('change', function (ev) {
            var alvo = ev.target;
            if (alvo.matches('.glpisas-switch input[type="checkbox"]') && !alvo.hasAttribute('data-glpisas-alternar')) {
                var rotulo = alvo.parentNode.querySelector('.form-check-label');
                if (rotulo) {
                    rotulo.textContent = alvo.checked ? 'Sim' : 'Não';
                }
            }
            if (alvo.matches('[data-glpisas-alternar]')) {
                var linha = alvo.closest('tr');
                enviar({ action: 'alternar', objeto: alvo.getAttribute('data-glpisas-alternar'), id: alvo.getAttribute('data-id') }).then(function (j) {
                    if (!j.success) {
                        alvo.checked = !alvo.checked;
                        aviso(j.message, true);
                        return;
                    }
                    alvo.checked = !!j.ativo;
                    if (linha) {
                        linha.classList.toggle('glpisas-inativo', !j.ativo);
                    }
                    aviso(j.message, false);
                }).catch(function () {
                    alvo.checked = !alvo.checked;
                    aviso('Falha de comunicação com o servidor.', true);
                });
            }
            if (alvo.matches('.glpisas-multi-item input')) {
                var caixa = alvo.closest('[data-glpisas-multi]');
                var busca = caixa.querySelector('.glpisas-multi-busca');
                if (busca.value !== '') {
                    busca.value = '';
                    multiFiltrar(caixa);
                    busca.focus();
                } else {
                    multiAtualizar(caixa);
                }
            }
            if (alvo.matches('.glpisas-multi-todos')) {
                var cx = alvo.closest('[data-glpisas-multi]');
                cx.querySelectorAll('.glpisas-multi-item').forEach(function (it) {
                    if (!it.hidden) {
                        it.querySelector('input').checked = alvo.checked;
                    }
                });
                multiAtualizar(cx);
            }
            var form = alvo.closest && alvo.closest('form.glpisas-form');
            if (form) {
                atualizarFormulario(form);
            }
        });

        // Select2 dispara "change" pelo jQuery
        if (window.jQuery) {
            window.jQuery(document).on('change', 'form.glpisas-form select', function () {
                atualizarFormulario(this.closest('form'));
            });
        }

        document.addEventListener('input', function (ev) {
            if (ev.target.matches('.glpisas-multi-busca')) {
                multiFiltrar(ev.target.closest('[data-glpisas-multi]'));
            }
        });

        document.addEventListener('focusout', function (ev) {
            if (ev.target.matches && ev.target.matches('.glpisas-moeda')) {
                formatarMoeda(ev.target);
            }
        });

        document.addEventListener('click', function (ev) {
            var botao = ev.target.closest('[data-glpisas-excluir]');
            if (botao) {
                ev.preventDefault();
                if (!botao.classList.contains('glpisas-confirmar')) {
                    botao.classList.add('glpisas-confirmar');
                    botao.setAttribute('data-html', botao.innerHTML);
                    botao.innerHTML = '<i class="ti ti-alert-triangle"></i> Confirmar exclusão';
                    botao.timer = setTimeout(function () {
                        botao.classList.remove('glpisas-confirmar');
                        botao.innerHTML = botao.getAttribute('data-html');
                    }, 4000);
                    return;
                }
                clearTimeout(botao.timer);
                botao.disabled = true;
                enviar({ action: 'excluir', objeto: botao.getAttribute('data-glpisas-excluir'), id: botao.getAttribute('data-id') }).then(function (j) {
                    if (j.success) {
                        window.location.reload();
                    } else {
                        botao.disabled = false;
                        aviso(j.message, true);
                    }
                });
                return;
            }
            var det = ev.target.closest('[data-glpisas-detalhes]');
            if (det) {
                ev.preventDefault();
                abrirDetalhes(det.getAttribute('data-glpisas-detalhes'));
            }
        });
    }

    function iniciar() {
        filtroEntidades();
        document.querySelectorAll('form.glpisas-form').forEach(atualizarFormulario);
        document.querySelectorAll('[data-glpisas-multi]').forEach(function (cx) {
            if (!cx.getAttribute('data-pronto')) {
                cx.setAttribute('data-pronto', '1');
                multiAtualizar(cx);
            }
        });
    }

    window.GlpiSas = { iniciar: iniciar };
    ligarEventos();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
