/*!
 * TAJHOST Two-Factor Authentication for FOSSBilling
 * Copyright 2026 TAJHOST (https://tajhost.com) | Apache-2.0
 */
(function (window, document) {
  'use strict';

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') { node.textContent = attrs[k]; }
      else if (k === 'html') { node.innerHTML = attrs[k]; }
      else if (k.indexOf('on') === 0) { node.addEventListener(k.slice(2), attrs[k]); }
      else { node.setAttribute(k, attrs[k]); }
    });
    (children || []).forEach(function (c) { if (c) { node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
    return node;
  }

  function mount(root, options) {
    var t = options.i18n || {};
    var api = window.FOSSBilling.api[options.role];
    var ui = window.FOSSBilling.ui;
    var prefix = 'tajhost2fa/';

    function call(method, params, done) {
      api.post(prefix + method, params || {}, function (res) { done(res); }, function (err) {
        ui.notify(err.message || String(err), 'error');
      });
    }

    function clear() { root.innerHTML = ''; }

    function codeInput(id) {
      return el('input', {
        id: id, class: 'form-control', type: 'text', inputmode: 'numeric', autocomplete: 'one-time-code',
        maxlength: '11', placeholder: '123456', style: 'max-width:12rem', spellcheck: 'false'
      });
    }

    function statusBadge(enabled) {
      return el('span', { class: 'badge ' + (enabled ? 'bg-success text-white' : 'bg-secondary text-white'), text: enabled ? t.on : t.off });
    }

    function renderStatus(status) {
      clear();
      var head = el('div', { class: 'd-flex align-items-center gap-2 mb-3' }, [
        el('strong', { text: t.status }), statusBadge(status.enabled)
      ]);
      root.appendChild(head);

      if (!status.available) {
        root.appendChild(el('p', { class: 'text-muted', text: t.unavailable }));
        return;
      }

      if (!status.enabled) {
        root.appendChild(el('p', { text: t.intro }));
        if (status.required) {
          root.appendChild(el('div', { class: 'alert alert-danger', text: t.required }));
        }
        root.appendChild(el('button', { class: 'btn btn-primary', type: 'button', text: t.enable, onclick: startSetup }));
        return;
      }

      var dl = el('dl', { class: 'row mb-3' }, [
        el('dt', { class: 'col-sm-4', text: t.enabledAt }), el('dd', { class: 'col-sm-8', text: status.enabled_at || '-' }),
        el('dt', { class: 'col-sm-4', text: t.lastUsed }), el('dd', { class: 'col-sm-8', text: status.last_used_at || '-' }),
        el('dt', { class: 'col-sm-4', text: t.backupLeft }), el('dd', { class: 'col-sm-8', text: String(status.backup_codes_left) })
      ]);
      root.appendChild(dl);

      // Regenerate backup codes
      var regenInput = codeInput('tajhost2fa-regen-code');
      root.appendChild(el('div', { class: 'mb-4' }, [
        el('h4', { class: 'mb-2', text: t.regenTitle }),
        el('p', { class: 'text-muted', text: t.regenHelp }),
        el('div', { class: 'd-flex gap-2 flex-wrap' }, [
          regenInput,
          el('button', {
            class: 'btn btn-outline-primary', type: 'button', text: t.regen, onclick: function () {
              call('backup_codes_regenerate', { code: regenInput.value }, function (res) { renderBackupCodes(res.backup_codes); });
            }
          })
        ])
      ]));

      // Disable
      if (!status.required) {
        var disableInput = codeInput('tajhost2fa-disable-code');
        disableInput.setAttribute('inputmode', 'text');
        disableInput.setAttribute('placeholder', t.codeOrBackup);
        root.appendChild(el('div', { class: 'mb-2' }, [
          el('h4', { class: 'mb-2', text: t.disableTitle }),
          el('p', { class: 'text-muted', text: t.disableHelp }),
          el('div', { class: 'd-flex gap-2 flex-wrap' }, [
            disableInput,
            el('button', {
              class: 'btn btn-outline-danger', type: 'button', text: t.disable, onclick: function () {
                if (!window.confirm(t.disableConfirm)) { return; }
                call('disable', { code: disableInput.value }, function () {
                  ui.notify(t.disabled, 'success');
                  load();
                });
              }
            })
          ])
        ]));
      }
    }

    function startSetup() {
      call('setup_start', {}, function (data) {
        clear();
        var qrBox = el('div', { class: 'p-2 bg-white d-inline-block border rounded', style: 'width:220px;height:220px' });
        try {
          var qr = window.qrcode(0, 'M');
          qr.addData(data.otpauth_uri);
          qr.make();
          qrBox.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
          var svg = qrBox.querySelector('svg');
          if (svg) { svg.setAttribute('width', '100%'); svg.setAttribute('height', '100%'); }
        } catch (e) {
          qrBox.textContent = t.qrError;
        }

        var confirmInput = codeInput('tajhost2fa-setup-code');
        root.appendChild(el('ol', { class: 'ps-3' }, [
          el('li', { class: 'mb-3' }, [
            el('div', { class: 'mb-2', text: t.step1 }),
            qrBox,
            el('div', { class: 'mt-2 text-muted small', text: t.manual }),
            el('code', { class: 'd-inline-block mt-1 fs-5 user-select-all', text: data.secret_formatted }),
            el('div', { class: 'text-muted small mt-1', text: data.issuer + ' — ' + data.account })
          ]),
          el('li', { class: 'mb-3' }, [
            el('div', { class: 'mb-2', text: t.step2 }),
            el('div', { class: 'd-flex gap-2 flex-wrap' }, [
              confirmInput,
              el('button', {
                class: 'btn btn-primary', type: 'button', text: t.verify, onclick: function () {
                  call('setup_confirm', { code: confirmInput.value }, function (res) {
                    ui.notify(t.enabledOk, 'success');
                    renderBackupCodes(res.backup_codes);
                  });
                }
              }),
              el('button', { class: 'btn btn-link', type: 'button', text: t.cancel, onclick: load })
            ])
          ])
        ]));
        confirmInput.focus();
      });
    }

    function renderBackupCodes(codes) {
      clear();
      var text = codes.join('\n');
      var list = el('div', { class: 'row g-2 mb-3', style: 'max-width:28rem' }, codes.map(function (c) {
        return el('div', { class: 'col-6' }, [el('code', { class: 'd-block p-2 border rounded text-center fs-5', text: c })]);
      }));
      root.appendChild(el('h4', { text: t.backupTitle }));
      root.appendChild(el('div', { class: 'alert alert-warning', text: t.backupHelp }));
      root.appendChild(list);
      root.appendChild(el('div', { class: 'd-flex gap-2 flex-wrap' }, [
        el('button', {
          class: 'btn btn-outline-secondary', type: 'button', text: t.copy, onclick: function () {
            if (navigator.clipboard) { navigator.clipboard.writeText(text).then(function () { ui.notify(t.copied, 'success'); }); }
          }
        }),
        el('button', {
          class: 'btn btn-outline-secondary', type: 'button', text: t.download, onclick: function () {
            var blob = new Blob([t.backupFileHeader + '\n\n' + text + '\n'], { type: 'text/plain' });
            var a = el('a', { href: URL.createObjectURL(blob), download: 'backup-codes.txt' });
            document.body.appendChild(a); a.click(); a.remove();
          }
        }),
        el('button', { class: 'btn btn-primary', type: 'button', text: t.done, onclick: function () { window.location.reload(); } })
      ]));
    }

    function load() {
      call('status', {}, renderStatus);
    }

    load();
  }

  window.TajHost2FA = { mount: mount };
})(window, document);
