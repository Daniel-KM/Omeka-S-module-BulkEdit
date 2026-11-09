$(document).ready(function() {

    // @todo To be removed when $this->form() will be used instead of $this->formCollection().

    const nav = '<nav class="section-nav">'
        + '<ul>'
        + '<li class="active"><a href="#batch-edit" id="batch-edit-label">' + Omeka.jsTranslate('Batch edit') + '</a></li>'
        + '<li><a href="#bulk-edit" id="bulk-edit-label">' + Omeka.jsTranslate('Advanced') + '</a></li>'
        + '</ul>'
        + '</nav>';
    $(nav).insertAfter('.batch-edit #page-actions');

    // Recreate missing bulk edit fieldsets.
    const bulkeditFieldsets = $('#bulkedit-fieldsets').data('bulkedit-fieldsets') || {};
    const bulkeditGroups = $('#bulkedit-fieldsets').data('bulkedit-groups') || {};
    const bulkeditRequired = $('#bulkedit-fieldsets').data('bulkedit-required') || {};
    const bulkeditBlocks = $('#bulkedit-fieldsets').data('bulkedit-blocks') || {};
    const bulkeditSteps = $('#bulkedit-fieldsets').data('bulkedit-steps') || {};
    $('#bulkedit-fieldsets').remove();
    Object.entries(bulkeditFieldsets).forEach(([fieldsetName, fieldsetLabel]) => {
        $('[data-bulkedit-fieldset=' + fieldsetName + ']').closest('.field')
            .wrapAll('<fieldset id="' + fieldsetName + '" class="field-container"><div class="collapsible">');
        $('#' + fieldsetName)
            .prepend('<a href="#" class="expand" aria-label="' + Omeka.jsTranslate('Expand') + '" title="' + Omeka.jsTranslate('Expand') + '">'
                + '<legend>' + fieldsetLabel + '</legend>'
                + '</a>');
    });

    // Wrap core form and bulk edit form with a section.
    $('.batch-edit #content > form:first-of-type > div, .batch-edit #content > form:first-of-type > fieldset')
        .filter(function () {
            const id = $(this).attr('id');
            return !bulkeditFieldsets.hasOwnProperty(id)
                && id !== 'page-actions';
        })
        .wrapAll('<div id="batch-edit" class="section active">');
    $('.batch-edit #content > form:first-of-type > fieldset')
        .filter(function () {
            return bulkeditFieldsets.hasOwnProperty($(this).attr('id'));
        })
        .wrapAll('<fieldset id="bulk-edit" class="section">');

    Object.entries(bulkeditGroups).forEach(([fieldsetName, groupLabel]) => {
        $('<h3 class="bulkedit-group">').text(groupLabel).insertBefore('#bulk-edit > #' + fieldsetName);
    });

    // Display the selection of the values and the operations of a fieldset
    // with blocks, each operation being enabled with its checkbox.
    Object.entries(bulkeditBlocks).forEach(([fieldsetName, blocks]) => {
        const container = $('#bulk-edit > #' + fieldsetName + ' > .collapsible');
        const [selectionLabel, operationsLabel] = bulkeditSteps[fieldsetName] || [Omeka.jsTranslate('Values to modify'), Omeka.jsTranslate('Operations, applied in this order')];
        const selection = $('<div class="bulkedit-step">')
            .append($('<h4>').text(selectionLabel))
            .append(container.children('.field').filter(function() {
                return !$(this).find('[data-bulkedit-block]').length;
            }));
        // The properties are required, the other elements filter the values.
        const filters = selection.children('.field').filter(function() {
            return !$(this).find('[name$="[properties][]"], [name$="[properties]"]').length;
        });
        if (filters.length < selection.children('.field').length) {
            $('<div class="bulkedit-filters">')
                .append($('<h5>').text(Omeka.jsTranslate('Filter the values')))
                .append(filters)
                .appendTo(selection);
        }
        const operations = $('<div class="bulkedit-step">')
            .append($('<h4>').text(operationsLabel));
        Object.keys(blocks).forEach((blockName) => {
            const fields = container.find('[data-bulkedit-block=' + blockName + ']').closest('.field');
            const checkbox = fields.find('input.bulkedit-operation-enabled');
            if (!checkbox.length) {
                return;
            }
            const checkboxField = checkbox.closest('.field');
            const toggle = $('<label class="bulkedit-operation-toggle">')
                .append(checkbox)
                .append($('<span>').text(checkboxField.find('.field-meta label').text()));
            checkboxField.remove();
            $('<div class="bulkedit-operation">').attr('data-block', blockName)
                .append(toggle)
                .append($('<div class="bulkedit-operation-fields">').append(fields.filter(function() {
                    return $.contains(document, this);
                })))
                .appendTo(operations);
        });
        container.prepend(selection.children('.field, .bulkedit-filters').length ? selection : '', operations);
    });

    // The preview of all the actions is at the end of the form.
    $('<div class="bulkedit-preview">')
        .append($('<h3 class="bulkedit-group">').text(Omeka.jsTranslate('Preview')))
        .append($('<p>').text(Omeka.jsTranslate('The actions on the values are processed without saving, except Value Suggest, the cleaning and the processes on media.')))
        .append($('<button type="button" class="button bulkedit-preview-button">').text(Omeka.jsTranslate('Preview the updated resources')))
        .append('<div class="bulkedit-preview-result"></div>')
        .appendTo('#bulk-edit');

    // Preview the values updated by the operations, without saving.
    // Each value is compared with its matching value on the other side, so the
    // updated text, data type, language or visibility is highlighted.
    const previewValue = (value, other) => {
        const badge = (text, isChanged) => $('<span class="bulkedit-preview-badge">').text(text)
            .toggleClass('bulkedit-preview-changed', isChanged);
        const item = $('<li>')
            .append($('<span>').text(value.value + (value.label ? ' | ' + value.label : ''))
                .toggleClass('bulkedit-preview-changed', !other || other.value !== value.value || other.label !== value.label))
            .append(badge(value.type_label, !!other && other.type !== value.type));
        if (value.language || (other && other.language)) {
            item.append(badge(value.language ? '@' + value.language : '@–', !!other && other.language !== value.language));
        }
        item.append(badge(Omeka.jsTranslate(value.is_public ? 'Public' : 'Private'), !!other && other.is_public !== value.is_public));
        return item;
    };
    // The values are matched by content, so the values added, removed or moved
    // do not shift the comparison: same value first, then same text, then
    // same position for the updated texts.
    const matchValues = (values, others) => {
        const matches = new Array(values.length).fill(null);
        const used = new Set();
        const text = (value) => value.value + '|' + value.label;
        const passes = [
            (value, other) => JSON.stringify(value) === JSON.stringify(other),
            (value, other) => text(value) === text(other),
            (value, other, index, otherIndex) => index === otherIndex,
        ];
        passes.forEach(isMatch => values.forEach((value, index) => {
            if (matches[index] !== null) {
                return;
            }
            const otherIndex = others.findIndex((other, i) => !used.has(i) && isMatch(value, other, index, i));
            if (otherIndex !== -1) {
                matches[index] = others[otherIndex];
                used.add(otherIndex);
            }
        }));
        return matches;
    };
    const previewValues = (values, others) => {
        const list = $('<ul class="bulkedit-preview-values">');
        const matches = matchValues(values, others);
        values.forEach((value, index) => list.append(previewValue(value, matches[index])));
        return list;
    };
    // The previous starts are stored to paginate backward.
    let previewStarts = [];
    const loadPreview = (start) => {
        const preview = $('.bulkedit-preview');
        const result = preview.find('.bulkedit-preview-result')
            .empty().append($('<p>').text(Omeka.jsTranslate('Loading…')));
        const formData = new FormData(preview.closest('form')[0]);
        formData.delete('batch_update');
        formData.append('resource_controller', (window.location.pathname.match(/\/admin\/([^\/]+)\/batch-edit/) || [])[1] || 'item');
        formData.append('preview_start', start);
        fetch($('#bulkedit-templates').data('preview-url'), {method: 'POST', body: formData})
            .then(response => response.json())
            .then(json => {
                result.empty();
                if (json.status !== 'success') {
                    result.append($('<p class="error">').text(json.message || Omeka.jsTranslate('An error occurred.')));
                    return;
                }
                const data = json.data;
                const last = data.next === null ? data.total : data.next;
                const pagination = $('<div class="bulkedit-preview-pagination">')
                    .append($('<span>').text(Omeka.jsTranslate('Resources {from}-{to} checked on {total}.')
                        .replace('{from}', data.total ? data.start + 1 : 0).replace('{to}', last).replace('{total}', data.total)));
                // The buttons are disabled on the first and the last pages.
                pagination
                    .append($('<button type="button" class="button bulkedit-preview-previous">').text(Omeka.jsTranslate('Previous'))
                        .prop('disabled', !previewStarts.length))
                    .append($('<button type="button" class="button bulkedit-preview-next">').attr('data-next', data.next).text(Omeka.jsTranslate('Next'))
                        .prop('disabled', data.next === null));
                result.append(pagination);
                if (!data.resources.length) {
                    result.append($('<p>').text(Omeka.jsTranslate('No value is updated in the {count} checked resources.').replace('{count}', data.checked)));
                }
                data.resources.forEach(resource => {
                    const table = $('<table class="tablesaw bulkedit-preview-table">')
                        .append($('<thead>').append($('<tr>')
                            .append($('<th>').text(Omeka.jsTranslate('Property')))
                            .append($('<th>').text(Omeka.jsTranslate('Before')))
                            .append($('<th>').text(Omeka.jsTranslate('After')))));
                    const body = $('<tbody>').appendTo(table);
                    resource.properties.forEach(property => {
                        body.append($('<tr>')
                            .append($('<td>').text(property.label || property.term))
                            .append($('<td>').append(previewValues(property.before, property.after)))
                            .append($('<td>').append(previewValues(property.after, property.before))));
                    });
                    result
                        .append($('<h5>').append($('<a target="_blank">').attr('href', resource.url).text(resource.title)))
                        .append(table);
                });
                if (data.resources.length > 2) {
                    result.append(pagination.clone());
                }
                preview.data('start', data.start);
            })
            .catch(() => {
                result.empty().append($('<p class="error">').text(Omeka.jsTranslate('An error occurred.')));
            });
    };
    $('#bulk-edit')
        .on('click', '.bulkedit-preview-button', function() {
            previewStarts = [];
            loadPreview(0);
        })
        .on('click', '.bulkedit-preview-next', function() {
            previewStarts.push($('.bulkedit-preview').data('start') || 0);
            loadPreview($(this).data('next'));
        })
        .on('click', '.bulkedit-preview-previous', function() {
            loadPreview(previewStarts.pop() || 0);
        });

    $('#bulk-edit').on('change', 'input.bulkedit-operation-enabled', function() {
        $(this).closest('.bulkedit-operation').toggleClass('is-enabled', this.checked);
    });
    $('input.bulkedit-operation-enabled').trigger('change');

    // Display the options according to the choice of the operation.
    const updateSubOptions = (operation) => {
        operation.find('[data-bulkedit-show]').closest('.field').each(function() {
            const field = $(this);
            const [name, values] = field.find('[data-bulkedit-show]').first().data('bulkedit-show').split('=');
            const fieldsetName = operation.closest('#bulk-edit > fieldset').attr('id');
            const block = operation.data('block');
            const checked = operation.find('[name="bulkedit[' + fieldsetName + '][' + block + '][' + name + ']"]:checked').val() || '';
            // The value "*" means any choice.
            field.addClass('bulkedit-suboption').toggle(values === '*' ? checked !== '' : values.split(' ').includes(checked));
        });
    };
    $('#bulk-edit').on('change', '.bulkedit-operation input[type=radio]', function() {
        updateSubOptions($(this).closest('.bulkedit-operation'));
    });
    $('.bulkedit-operation').each(function() {
        updateSubOptions($(this));
    });

    // The checkbox "exact" is displayed on the right of the string.
    $('#bulk-edit input.bulkedit-contains-exact').each(function() {
        const checkboxField = $(this).closest('.field');
        const name = checkboxField.closest('#bulk-edit > fieldset').attr('id');
        const containsInputs = $('[name="bulkedit[' + name + '][contains]"]').closest('.inputs');
        $('<label class="bulkedit-contains-exact-label">')
            .append(this)
            .append($('<span>').text(checkboxField.find('.field-meta label').text()))
            .appendTo(containsInputs);
        checkboxField.remove();
    });

    // The filters and the options of some actions are displayed when the
    // properties are selected, like the action "modify".
    const dependentFieldsets = {
        copy: ['from', ['datatypes', 'languages', 'contains', 'match', 'visibility', 'search_in'], ['datatype', 'max_values']],
        displace: ['from', ['datatypes', 'languages', 'contains', 'match', 'visibility', 'search_in'], ['datatype', 'max_values']],
        fill_values: ['properties', ['datatypes', 'languages', 'contains', 'match', 'is_public', 'search_in'], ['note_uri', 'datatype', 'language', 'update_language', 'featured_subject']],
        remove: ['properties', ['datatypes', 'languages', 'visibility', 'contains', 'match', 'search_in'], ['keep_max_values', 'max_values']],
        media_order: ['order', [], ['mediatypes', 'extensions']],
    };
    Object.entries(dependentFieldsets).forEach(([fieldsetName, [trigger, filterNames, optionNames]]) => {
        const container = $('#bulk-edit > #' + fieldsetName + ' > .collapsible');
        if (!container.length) {
            return;
        }
        // The notes have no name, so they are selected with their data name.
        const fields = (names) => container.find(names
            .map(name => '[name="bulkedit[' + fieldsetName + '][' + name + ']"], [name="bulkedit[' + fieldsetName + '][' + name + '][]"], [id$="_' + name + '"].field')
            .join(', ')).closest('.field');
        const dependent = $('<div class="bulkedit-dependent">').appendTo(container);
        if (filterNames.length) {
            $('<div class="bulkedit-filters">')
                .append($('<h5>').text(Omeka.jsTranslate('Filter the values')))
                .append(fields(filterNames))
                .appendTo(dependent);
        }
        // The options of Value Suggest are required, so they are open.
        $('<div class="bulkedit-filters">')
            .toggleClass('bulkedit-filters-open', fieldsetName === 'fill_values')
            .append($('<h5>').text(Omeka.jsTranslate('Options')))
            .append(fields(optionNames))
            .appendTo(dependent);
        // The trigger may be a select, a multiple select or radios.
        const inputs = fields([trigger]).find('select, input[type=radio]');
        const value = () => inputs.is('select') ? inputs.val() : (inputs.filter(':checked').val() || '');
        inputs.on('change', () => dependent.toggle(!!(value() || []).length));
        dependent.toggle(!!(value() || []).length);
    });

    // The parts of the values to search in are useful only with a string.
    $('#bulk-edit .bulkedit-search-in').closest('.field').each(function() {
        const field = $(this);
        const name = field.closest('#bulk-edit > fieldset').attr('id');
        const strings = $('[name="bulkedit[' + name + '][contains]"], [name="bulkedit[' + name + '][match]"]');
        const toggle = () => field.toggle(strings.toArray().some(input => input.value !== ''));
        strings.on('input change', toggle);
        toggle();
    });

    // The filters and the options are collapsed, but open when one is not the
    // default value.
    $('#bulk-edit .bulkedit-filters').each(function() {
        const block = $(this);
        const heading = block.children('h5');
        const content = $('<div class="collapsible">').append(block.children().not(heading));
        const isSet = content.find('input[type=text], input[type=number], textarea, select').toArray()
            .some(input => {
                if (input.tagName !== 'SELECT') {
                    return input.value !== input.defaultValue;
                }
                // Without default option, the first one is selected.
                const options = [...input.options];
                return options.some(option => option.defaultSelected)
                    ? options.some(option => option.selected !== option.defaultSelected)
                    : (input.multiple ? options.some(option => option.selected) : input.selectedIndex > 0);
            });
        const isOpen = isSet || block.hasClass('bulkedit-filters-open');
        $('<a href="#">').addClass(isOpen ? 'collapse' : 'expand')
            .attr('aria-label', Omeka.jsTranslate(isOpen ? 'Collapse' : 'Expand'))
            .attr('title', Omeka.jsTranslate(isOpen ? 'Collapse' : 'Expand'))
            .text(heading.text())
            .replaceAll(heading);
        block.append(content);
    });

    // Gather the secondary elements in a collapsed block.
    $('#bulk-edit > fieldset').each(function() {
        const options = $(this).find('[data-bulkedit-option]').closest('.field');
        if (options.length) {
            $('<div class="bulkedit-options">')
                .append('<a href="#" class="expand" aria-label="' + Omeka.jsTranslate('Expand') + '" title="' + Omeka.jsTranslate('Expand') + '">' + Omeka.jsTranslate('Options') + '</a>')
                .append($('<div class="collapsible">').append(options))
                .appendTo($(this).children('.collapsible'));
        }
    });

    // Hidden inputs that should not be after the inputs.
    $('.batch-edit #content > form:first-of-type input[type=hidden][name=set_value_visibility]').insertAfter($('.batch-edit #content > form:first-of-type input[name=csrf]'));
    $('.batch-edit #content > form:first-of-type input[type=hidden][name=value]').insertAfter($('.batch-edit #content > form:first-of-type input[name=csrf]'));

    // For better ux.

    $('#bulk-edit')
        .prepend('<p>' + Omeka.jsTranslate('The actions are processed in the order of the form. Be careful when mixing them.') + '</p>');
    if (!$('input#geometry_convert_literal_to_coordinates').length) {
        $('#convert_from').closest('.bulkedit-operation-fields, .collapsible')
            .append('<p>' + Omeka.jsTranslate('To convert values to/from mapping markers, use module DataTypeGeometry.') + '</p>');
    }

    $('#fill_values > .collapsible, #media_explode > .collapsible')
        .prepend('<p>' + Omeka.jsTranslate('Slow process, always run in background.') + '</p>')

    // Mark the actions that will be processed, with the same required elements
    // than the server.
    const fieldValues = (fieldset, name) => {
        // A path "block/name" is used for the elements of the blocks.
        const fieldName = 'bulkedit[' + fieldset.attr('id') + '][' + name.split('/').join('][') + ']';
        return fieldset.find(':input')
            .filter(function() {
                return (this.name === fieldName || this.name === fieldName + '[]')
                    && (!['checkbox', 'radio'].includes(this.type) || this.checked);
            })
            .map(function() { return $(this).val(); }).get()
            .flat().filter(value => value !== null && value !== '');
    };
    const isFilled = (fieldset, token) => token.split('+').every(part => {
        const [name, value] = part.split('=');
        const values = fieldValues(fieldset, name);
        return value === undefined ? values.length > 0 : values.includes(value);
    });
    const isRequiredFilled = (fieldset, required) => required
        .every(group => group.some(token => isFilled(fieldset, token)));
    // A fieldset with blocks is active when one of its blocks is active.
    const isActive = (fieldset) => {
        const required = bulkeditRequired[fieldset.attr('id')] || [[]];
        if (!required.any) {
            return isRequiredFilled(fieldset, required);
        }
        let active = false;
        Object.entries(required.any).forEach(([blockName, blockRequired]) => {
            const isBlockActive = isRequiredFilled(fieldset, blockRequired);
            fieldset.find('.bulkedit-operation[data-block="' + blockName + '"]').toggleClass('bulkedit-active', isBlockActive);
            active = active || isBlockActive;
        });
        return active;
    };
    $('#bulk-edit').on('change input', ':input', function() {
        const fieldset = $(this).closest('#bulk-edit > fieldset');
        fieldset.toggleClass('bulkedit-active', isActive(fieldset));
    });

    $('.batch-edit').on('change', '.bulkedit-replace-mode', function() {
        const fieldset = $(this).closest('.bulkedit-operation, fieldset');
        // The mode may be a select or radios.
        const modes = fieldset.find('.bulkedit-replace-mode');
        const mode = modes.is('select') ? modes.val() : (modes.filter(':checked').val() || '');
        fieldset.find('[name$="[from]"]').closest('.field').toggle(['raw', 'raw_i', 'html', 'regex'].includes(mode));
        fieldset.find('[name$="[to]"]').closest('.field').toggle(['raw', 'raw_i', 'html', 'regex', 'whole'].includes(mode));
    });
    $('.bulkedit-replace-mode').trigger('change');

    $('.batch-edit').on('change', '[name="bulkedit[thumbnails][mode]"]', function() {
        const mode = $('[name="bulkedit[thumbnails][mode]"]:checked').val() || '';
        $('#thumbnails_asset').closest('.field').toggle(!['', 'remove_primary', 'delete'].includes(mode));
    });
    $('[name="bulkedit[thumbnails][mode]"]').first().trigger('change');

    $('.batch-edit').on('change', '[name="bulkedit[media_remove][mode]"]', function() {
        const mode = $('[name="bulkedit[media_remove][mode]"]:checked').val() || '';
        $('#mediaremove_mediatypes').closest('.field').toggle(mode === 'media_type');
        $('#mediaremove_extensions').closest('.field').toggle(mode === 'media_extension');
    });
    $('[name="bulkedit[media_remove][mode]"]').first().trigger('change');

    $('.batch-edit').on('change', '#cleaning_clean_language_codes', function() {
        const fields = $('#cleaning_clean_language_codes_from, #cleaning_clean_language_codes_to, #cleaning_clean_language_codes_properties').closest('.field');
        this.checked ? fields.show() : fields.hide();
    });
    $('#cleaning_clean_language_codes').trigger('change');

    $('.batch-edit').on('change', '#convert_from, #convert_to', function() {
        const datatypes = $('#convert_from').data('datatypes');
        const datatypeFrom = $('#convert_from').val();
        const datatypeTo = $('#convert_to').val();
        const block = $('#convert_from').closest('.bulkedit-operation-fields, .collapsible');
        block.find('[data-info-datatype]').closest('.field').hide();
        if (!datatypes[datatypeTo]) {
            return;
        }
        block.find('[data-info-datatype=' + datatypes[datatypeTo] + ']').closest('.field').show();
        if (!datatypes[datatypeFrom]) {
            return;
        }
        if (datatypes[datatypeTo] === 'literal') {
            if (datatypes[datatypeFrom] !== 'uri') {
                $('#convert_literal_value').closest('.field').hide();
            }
            $('#convert_literal_extract_html_text').closest('.field').hide();
            $('#convert_literal_html_only_tagged_string').closest('.field').hide();
            if (datatypeFrom === 'html' || datatypeFrom === 'xml') {
                $('#convert_literal_extract_html_text').closest('.field').show();
            }
            if (datatypes[datatypeTo] === 'literal' && (datatypeTo === 'html' || datatypeTo === 'xml')) {
                $('#convert_literal_html_only_tagged_string').closest('.field').show();
            }
        }
        if (datatypes[datatypeTo] === 'resource' && datatypes[datatypeFrom] !== 'literal') {
            $('#convert_resource_properties').closest('.field').hide();
        }
        if (datatypes[datatypeTo] === 'uri') {
            if (datatypes[datatypeFrom] !== 'literal') {
                $('#convert_uri_extract_label').closest('.field').hide();
            }
            if (datatypes[datatypeFrom] !== 'resource') {
                $('#convert_uri_base_site').closest('.field').hide();
            }
            if (datatypes[datatypeFrom] === 'uri') {
                $('#convert_uri_extract_label').closest('.field').hide();
            }
        }
    });
    $('#convert_from, #convert_to').trigger('change');

    $('.batch-edit').on('change', 'input[name="bulkedit[fill_values][mode]"]', function() {
        const fieldset = $(this).closest('fieldset');
        const mainMode = $(this).data('fill-main').length ? $(this).data('fill-main') : 'label';
        fieldset.find('[data-fill-mode=' + mainMode + ']').closest('.field').show();
        fieldset.find('[data-fill-mode]').not('[data-fill-mode=' + mainMode + ']').closest('.field').hide();
        fieldset.find('[data-fill-mode-option=' + mainMode + ']').show();
        fieldset.find('[data-fill-mode-option]').not('[data-fill-mode-option=' + mainMode + ']').hide();
        fieldset.find('[data-fill-mode-option]').closest('select').trigger('chosen:updated');
    });
    // $('input[name="bulkedit[fill_values][mode]"]').trigger('change');

    // From resource-form.js.
    $('input.value-language').on('keyup', function(e) {
        if ('' === this.value || Omeka.langIsValid(this.value)) {
            this.setCustomValidity('');
        } else {
            this.setCustomValidity(Omeka.jsTranslate('Please enter a valid language tag'));
        }
    });

    // From admin.js.
    // Switch between section tabs.
    $('.section-nav a[href^="#"]').click(function (e) {
        e.preventDefault();
        Omeka.switchActiveSection($($(this).attr('href')));
    });
    $('.section > legend').click(function() {
        $(this).parent().toggleClass('mobile-active');
    });

    /**
     * There is a bug during hydration in abstractResourceEntityAdapter or
     * ValueHydrator: when there is an operation "replace" empty (?), the
     * operation "remove" does not work. Try to remove a property disabling
     * all events of the modules except "form.add_elements".
     * So just remove bulkedit if there is no data.
     * Fixed in Omeka S v4.0.2 (#609dbbb30).
     */
    const bulkEditDefaults = {
        'bulkedit[cleaning][trim_values]': '0',
        'bulkedit[cleaning][specify_datatypes]': '0',
        'bulkedit[cleaning][clean_languages]': '0',
        'bulkedit[cleaning][clean_language_codes]': '0',
        'bulkedit[cleaning][clean_language_codes_from]': '',
        'bulkedit[cleaning][clean_language_codes_to]': '',
        'bulkedit[cleaning][deduplicate_values]': '0',
        'bulkedit[displace][to]': '',
        'bulkedit[displace][languages]': '',
        'bulkedit[displace][visibility]': '',
        'bulkedit[displace][contains]': '',
        'bulkedit[fill_data][owner]': '',
        'bulkedit[fill_values][datatype]': '',
        'bulkedit[fill_values][language]': '',
        'bulkedit[fill_values][update_language]': 'keep',
        'bulkedit[fill_values][featured_subject]': '0',
        'bulkedit[remove][languages]': '',
        'bulkedit[remove][visibility]': '',
        'bulkedit[remove][contains]': '',
        'bulkedit[explode_item][mode]': '',
        'bulkedit[explode_pdf][mode]': '',
        'bulkedit[explode_pdf][process]': 'all',
        'bulkedit[explode_pdf][resolution]': '',
        'bulkedit[explode_pdf][process_events]': '0',
        'bulkedit[media_order][order]': '',
        'bulkedit[media_order][mediatypes]': 'video audio image application/pdf',
        'bulkedit[media_order][extensions]': '',
        'bulkedit[media_html][from]': '',
        'bulkedit[media_html][to]': '',
        'bulkedit[media_html][mode]': 'raw',
        'bulkedit[media_html][remove]': '0',
        'bulkedit[media_html][prepend]': '',
        'bulkedit[media_html][append]': '',
        'bulkedit[media_type][from]': '',
        'bulkedit[media_type][to]': '',
        'bulkedit[media_visibility][visibility]': '',
        'bulkedit[create_values][properties]': '',
        'bulkedit[create_values][values]': '',
    };

   $('#content form').on('submit', function(e) {
        const form = $(this);
        const formData = new FormData(form[0]);
        for (const [name, value] of formData) {
            if (bulkEditDefaults.hasOwnProperty(name) && bulkEditDefaults[name] !== value) {
                return true;
            }
        }
        Object.keys(bulkEditDefaults).forEach(name => {
            form.find('[name="' + name + '"]').remove();
            formData.delete(name);
        });
        return true;
    });

    /**
     * Fix a strange issue with FormData for value "'bulkedit[fill_values][datatypes]'",
     * that is an array of values that should not contain an empty string.
     */
    $('#content form').on('submit', function(e) {
        const form = $(this);
        const formData = new FormData(form[0]);
        const fillValuesDatatypes = formData.getAll('bulkedit[fill_values][datatypes]');
        if (fillValuesDatatypes.length && fillValuesDatatypes[0] === '') {
            form.find('[name="bulkedit[fill_values][datatypes]"]').remove();
            formData.delete('bulkedit[fill_values][datatypes]');
            fillValuesDatatypes.shift();
            fillValuesDatatypes.forEach(value => {
                form.append('<input type="hidden" name="bulkedit[fill_values][datatypes][]" value="' + value + '">');
            });
        }
         return true;
     });

 });
