/**
 * GUS / MF White List NIP Lookup
 *
 * Queries the Polish Ministry of Finance API when a 10-digit NIP is
 * entered in either the B2B registration form (justb2b_nip) or the
 * WooCommerce checkout billing form (billing_nip).
 */
(function ($) {
    'use strict';

    // ── Form configurations ──────────────────────────────────────────────
    // Each entry declares which NIP input to watch and which target fields
    // to populate.  afterFill() is invoked once the fields are written.

    var FORMS = [
        {
            nip:       'input[name="justb2b_nip"]',
            company:   'input[name="justb2b_company"]',
            city:      'input[name="justb2b_city"]',
            postcode:  'input[name="justb2b_postcode"]',
            address:   'input[name="justb2b_address_1"]',
            afterFill: null,
        },
        {
            nip:       'input[name="billing_nip"]',
            company:   'input[name="billing_company"]',
            city:      'input[name="billing_city"]',
            postcode:  'input[name="billing_postcode"]',
            address:   'input[name="billing_address_1"]',
            afterFill: function () {
                $(document.body).trigger('update_checkout');
            },
        },
    ];

    // ── Address parser ───────────────────────────────────────────────────
    // Splits a MF API workingAddress/residenceAddress string of the form
    // "STREET, POSTCODE CITY" into separate parts.

    function parseAddress(raw) {
        var result = { street: '', postcode: '', city: '' };
        if (!raw) { return result; }

        var parts = raw.split(',').map(function (s) { return s.trim(); });

        if (parts.length >= 2) {
            result.street = parts[0];
            var rest = parts[1];
            var m = rest.match(/(\d{2}-\d{3})/);
            if (m) {
                result.postcode = m[1];
                result.city     = rest.replace(m[1], '').trim();
            } else {
                result.city = rest.trim();
            }
        } else {
            var m2 = raw.match(/(\d{2}-\d{3})/);
            if (m2) {
                result.postcode = m2[1];
                result.street   = raw.substring(0, m2.index).trim();
                result.city     = raw.substring(m2.index + m2[1].length).trim();
            } else {
                result.street = raw;
            }
        }

        return result;
    }

    // ── NIP checksum validation ──────────────────────────────────────────
    // Weights defined by the Polish tax authority (GUS/MF).
    // Sum of (digit[i] * weight[i]) for i=0..8, modulo 11 must equal digit[9].
    // A remainder of 10 is never assigned, so any such value is invalid.

    var NIP_WEIGHTS = [6, 5, 7, 2, 3, 4, 5, 6, 7];

    function validateNIP(nip) {
        nip = nip.replace(/[\s-]/g, '');
        if (nip.length === 10 && parseInt(nip, 10) > 0) {
            var sum = 0;
            for (var i = 0; i < 9; i++) {
                sum += nip[i] * NIP_WEIGHTS[i];
            }
            return (sum % 11) === Number(nip[9]);
        }
        return false;
    }

    // ── MF White List API call ───────────────────────────────────────────

    function fetchNIP(nip) {
        var date = new Date().toISOString().split('T')[0];
        var url  = 'https://wl-api.mf.gov.pl/api/search/nip/' + nip + '?date=' + date;

        return window.fetch(url, { headers: { Accept: 'application/json' } })
            .then(function (res) {
                if (!res.ok) { return null; }
                return res.json();
            })
            .then(function (json) {
                if (!json || !json.result || !json.result.subject) { return null; }
                var s    = json.result.subject;
                var addr = parseAddress(s.workingAddress || s.residenceAddress || '');
                return {
                    company:  s.name || '',
                    street:   addr.street,
                    postcode: addr.postcode,
                    city:     addr.city,
                };
            })
            .catch(function () { return null; });
    }

    // ── Field fill helper ────────────────────────────────────────────────

    function fill(selector, value) {
        if (!value) { return; }
        var $el = $(selector);
        if ($el.length) { $el.val(value).trigger('change'); }
    }

    // ── Attach lookup to a single NIP input ──────────────────────────────

    function initField($nip, form) {
        if ($nip.data('gus-init')) { return; }
        $nip.data('gus-init', true);

        // Status indicator inserted immediately after the NIP input
        if (!$nip.next('.gus-status').length) {
            $nip.after('<span class="gus-status" aria-live="polite"></span>');
        }
        var $status = $nip.next('.gus-status');

        var lastNIP = '';

        function showError(msg) {
            $status.html('<span style="color:#c0392b;">' + msg + '</span>');
        }

        function tryLookup(raw) {
            if (raw === lastNIP) { return; }
            lastNIP = raw;

            $status.html('<span style="color:#666;">Wyszukiwanie…</span>');

            fetchNIP(raw).then(function (data) {
                if (!data) {
                    showError('Nie znaleziono danych dla tego NIP');
                    setTimeout(function () { $status.html(''); }, 4000);
                    return;
                }

                fill(form.company,  data.company);
                fill(form.address,  data.street);
                fill(form.postcode, data.postcode);
                fill(form.city,     data.city);

                if (typeof form.afterFill === 'function') { form.afterFill(); }

                $status.html('<span style="color:#27ae60;">✓ Uzupełniono</span>');
                setTimeout(function () { $status.html(''); }, 3000);
            });
        }

        // While typing: give instant feedback on illegal characters or excess length
        $nip.on('input.gus', function () {
            var val = $(this).val();
            var raw = val.replace(/[\s-]/g, '');
            $status.html('');

            if (val.length === 0) { return; }

            if (/[^\d\s-]/.test(val)) {
                showError('NIP może zawierać tylko cyfry');
                return;
            }
            if (raw.length > 10) {
                showError('NIP ma za dużo cyfr — wymagane dokładnie 10');
                return;
            }
            // Full check once 10 digits are present
            if (raw.length === 10) {
                if (!validateNIP(raw)) {
                    showError('Nieprawidłowy NIP (błędna cyfra kontrolna)');
                    return;
                }
                tryLookup(raw);
            }
        });

        // On blur: validate the complete value and report any remaining issues
        $nip.on('blur.gus', function () {
            var val = $(this).val();
            var raw = val.replace(/[\s-]/g, '');
            $status.html('');

            if (raw.length === 0) { return; }

            if (/[^\d\s-]/.test(val)) {
                showError('NIP może zawierać tylko cyfry');
                return;
            }
            if (raw.length < 10) {
                showError('NIP jest za krótki — wymagane dokładnie 10 cyfr (wpisano ' + raw.length + ')');
                return;
            }
            if (raw.length > 10) {
                showError('NIP ma za dużo cyfr — wymagane dokładnie 10 (wpisano ' + raw.length + ')');
                return;
            }
            if (!validateNIP(raw)) {
                showError('Nieprawidłowy NIP (błędna cyfra kontrolna)');
                return;
            }
            tryLookup(raw);
        });
    }

    // ── Initialise all configured forms ──────────────────────────────────

    function init() {
        FORMS.forEach(function (form) {
            $(form.nip).each(function () {
                initField($(this), form);
            });
        });
    }

    $(function () {
        init();
        // Re-init after AJAX (e.g. WooCommerce checkout fragment refresh)
        $(document).ajaxComplete(function () { setTimeout(init, 150); });
    });

}(jQuery));
