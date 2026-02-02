/**
 * GUS NIP Lookup Script
 * Automatically fills form fields when user enters a 10-digit NIP
 */
(function ($) {
    'use strict';

    /**
     * Lookup company data from Polish Ministry of Finance White List API
     */
    async function lookupNIP(nip) {
        try {
            // Clean NIP - remove spaces and dashes
            nip = nip.replace(/[\s-]/g, '');

            // Validate NIP format (10 digits)
            if (!/^\d{10}$/.test(nip)) {
                return null;
            }

            // Get current date in YYYY-MM-DD format
            const today = new Date();
            const dateStr = today.toISOString().split('T')[0];

            // Use Polish Ministry of Finance White List API
            const response = await fetch(`https://wl-api.mf.gov.pl/api/search/nip/${nip}?date=${dateStr}`, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) {
                console.error('MF API request failed:', response.status);
                return null;
            }

            const data = await response.json();
            
            // Check if company was found
            if (!data || !data.result || !data.result.subject) {
                return null;
            }

            const subject = data.result.subject;
            
            // Extract address components
            let street = '';
            let buildingNumber = '';
            let apartmentNumber = '';
            let city = '';
            let postcode = '';
            
            if (subject.workingAddress) {
                street = subject.workingAddress || '';
            }
            
            if (subject.residenceAddress) {
                const addressParts = subject.residenceAddress.split(',').map(s => s.trim());
                
                // Try to parse address (format varies, best effort)
                addressParts.forEach(part => {
                    if (/^\d{2}-\d{3}$/.test(part)) {
                        // This is a postcode
                        postcode = part;
                    } else if (/^\d+/.test(part) && !street) {
                        // Starts with number, might be street with number
                        street = part;
                    } else if (!city && !postcode) {
                        // Likely a city name
                        city = part;
                    }
                });
            }
            
            return {
                company_name: subject.name || '',
                first_name: '',
                last_name: '',
                street: street,
                building_number: buildingNumber,
                apartment_number: apartmentNumber,
                city: city,
                postcode: postcode,
                phone: '',
                email: '',
                regon: subject.regon || '',
                krs: subject.krs || '',
            };

        } catch (error) {
            console.error('Error looking up NIP:', error);
            return null;
        }
    }

    /**
     * Fill form fields with company data
     */
    function fillFormFields(data) {
        if (!data) return;

        // Map GUS data to form fields
        const fieldMapping = {
            'justb2b_company': data.company_name,
            'uwp-justb2b_company': data.company_name,
            'billing_company': data.company_name,
            
            'justb2b_firstname': data.first_name,
            'uwp-justb2b_firstname': data.first_name,
            'billing_first_name': data.first_name,
            
            'justb2b_lastname': data.last_name,
            'uwp-justb2b_lastname': data.last_name,
            'billing_last_name': data.last_name,
            
            'justb2b_city': data.city,
            'uwp-justb2b_city': data.city,
            'billing_city': data.city,
            
            'justb2b_postcode': data.postcode,
            'uwp-justb2b_postcode': data.postcode,
            'billing_postcode': data.postcode,
            
            'justb2b_phone': data.phone,
            'uwp-justb2b_phone': data.phone,
            'billing_phone': data.phone,
            
            'justb2b_email': data.email,
            'uwp-justb2b_email': data.email,
            'billing_email': data.email,
        };

        // Construct address from street, building number, and apartment
        let address = data.street || '';
        if (data.building_number) {
            address += (address ? ' ' : '') + data.building_number;
        }
        if (data.apartment_number) {
            address += '/' + data.apartment_number;
        }

        if (address) {
            fieldMapping['justb2b_address_1'] = address;
            fieldMapping['uwp-justb2b_address_1'] = address;
            fieldMapping['billing_address_1'] = address;
        }

        // Fill the fields
        Object.keys(fieldMapping).forEach(function (fieldName) {
            const value = fieldMapping[fieldName];
            if (!value) return;

            // Try different field selectors
            const selectors = [
                `input[name="${fieldName}"]`,
                `input[id="${fieldName}"]`,
                `input[name="uwp-${fieldName}"]`,
                `input[id="uwp-${fieldName}"]`,
                `select[name="${fieldName}"]`,
                `select[id="${fieldName}"]`,
                `textarea[name="${fieldName}"]`,
                `textarea[id="${fieldName}"]`,
            ];

            selectors.forEach(function (selector) {
                const $field = $(selector);
                if ($field.length && !$field.val()) {
                    $field.val(value).trigger('change');
                }
            });
        });

        // Set Poland as country if field exists
        const countrySelectors = [
            'select[name="justb2b_country"]',
            'select[name="uwp-justb2b_country"]',
            'select[name="billing_country"]',
            'select[id="justb2b_country"]',
            'select[id="billing_country"]',
        ];

        countrySelectors.forEach(function (selector) {
            const $field = $(selector);
            if ($field.length && !$field.val()) {
                $field.val('PL').trigger('change');
            }
        });
    }

    /**
     * Initialize NIP lookup functionality
     */
    function initNIPLookup() {
        // Find NIP input fields
        const nipSelectors = [
            'input[name="justb2b_nip"]',
            'input[name="uwp-justb2b_nip"]',
            'input[id="justb2b_nip"]',
            'input[id="uwp-justb2b_nip"]',
            'input[name="billing_nip"]',
        ];

        nipSelectors.forEach(function (selector) {
            const $nipField = $(selector);
            
            if ($nipField.length) {
                // Add loading indicator container if not exists
                if (!$nipField.next('.gus-lookup-status').length) {
                    $nipField.after('<span class="gus-lookup-status"></span>');
                }

                const $status = $nipField.next('.gus-lookup-status');

                // Add event listener for NIP input
                $nipField.on('input blur', function () {
                    const nip = $(this).val().replace(/[\s-]/g, '');

                    // Clear status
                    $status.html('');

                    // Only proceed if we have 10 digits
                    if (nip.length === 10 && /^\d{10}$/.test(nip)) {
                        // Show loading indicator
                        $status.html('<span style="color: #666;">🔍 Wyszukiwanie w bazie MF...</span>');

                        // Lookup NIP data
                        lookupNIP(nip).then(function (data) {
                            if (data) {
                                $status.html('<span style="color: #4CAF50;">✓ Dane znalezione</span>');
                                fillFormFields(data);
                                
                                // Clear status after 3 seconds
                                setTimeout(function () {
                                    $status.html('');
                                }, 3000);
                            } else {
                                $status.html('<span style="color: #ff9800;">⚠ Nie znaleziono danych dla tego NIP</span>');
                                
                                // Clear status after 5 seconds
                                setTimeout(function () {
                                    $status.html('');
                                }, 5000);
                            }
                        }).catch(function (error) {
                            console.error('NIP lookup error:', error);
                            $status.html('<span style="color: #f44336;">✗ Błąd podczas wyszukiwania</span>');
                            
                            // Clear status after 5 seconds
                            setTimeout(function () {
                                $status.html('');
                            }, 5000);
                        });
                    }
                });
            }
        });
    }

    // Initialize on document ready
    $(document).ready(function () {
        initNIPLookup();

        // Re-initialize on AJAX complete (for dynamic forms)
        $(document).ajaxComplete(function () {
            setTimeout(initNIPLookup, 100);
        });
    });

})(jQuery);
