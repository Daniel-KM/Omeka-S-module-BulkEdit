/**
 * Create values with the templates and the design of the resource form.
 *
 * The core resource-form.js cannot be used, because it requires the full
 * resource page, so the main features are replicated here.
 */
$(document).ready(function() {

    const fieldset = $('#create_values');
    const templates = $('#bulkedit-templates');
    const picker = $('#create_values_properties');
    const valuesInput = $('#create_values_values');
    if (!fieldset.length || !templates.length || !picker.length) {
        return;
    }

    // Hidden inputs have no field, so they are not moved in the fieldset.
    valuesInput.appendTo(fieldset.children('.collapsible'));

    const propertyTemplate = templates.find('.resource-property.template');
    const dataTypeSelect = templates.find('select.bulkedit-add-value');
    const properties = $('<div class="bulkedit-create-values">').insertBefore(picker.closest('.field'));

    const makeValue = (dataType) => {
        const value = templates.find('.value.template[data-data-type="' + dataType + '"]').first()
            .clone(true).removeClass('template');
        value.find('.sortable-handle, .value-annotation-annotate, .restore-value').remove();
        value.find('input.is_public').val('1');
        // The sidebar to select a resource is not available, so use the id.
        value.find('.resource-inputs').replaceWith(
            $('<input type="number" min="1" class="to-require" data-value-key="value_resource_id">')
                .attr('placeholder', Omeka.jsTranslate('Resource id'))
        );
        $(document).trigger('o:prepare-value', [dataType, value]);
        value.find(':input').removeAttr('name');
        return value;
    };

    const makeField = (term, label) => {
        let field = properties.children('.resource-property').filter(function() {
            return $(this).attr('data-property-term') === term;
        });
        if (field.length) {
            return field;
        }
        field = propertyTemplate.clone(true).removeClass('template')
            .attr('data-property-term', term);
        field.find('.field-label').text(label);
        field.find('.field-meta > a.collapse, .field-meta > .collapsible').remove();
        field.find('.add-values.multiple-selector, .add-values.single-selector').remove();
        properties.append(field);
        // Outside of the buttons, that have a specific css.
        $('<div class="bulkedit-add-value-wrapper">')
            .append(dataTypeSelect.clone().removeAttr('name'))
            .insertAfter(field.find('.add-values.default-selector'))
            .children('select')
            .chosen({allow_single_deselect: true, include_group_label_in_selected: true, width: '100%'});
        return field;
    };

    const addValue = (field, dataType) => {
        field.find('.no-values').remove();
        const value = makeValue(dataType);
        field.find('.values').append(value);
        updateValues();
    };

    // Store the values as json, only when a value is filled.
    const updateValues = () => {
        const values = {};
        let isFilled = false;
        properties.children('.resource-property').each(function() {
            const term = $(this).attr('data-property-term');
            $(this).find('.values > .value').each(function() {
                const value = $(this);
                const valueData = {
                    type: value.attr('data-data-type'),
                    is_public: value.find('input.is_public').val(),
                };
                value.find(':input[data-value-key]').each(function() {
                    const key = $(this).data('valueKey');
                    const val = $(this).val();
                    valueData[key] = val;
                    if (val && !['@language', 'o:lang'].includes(key)) {
                        isFilled = true;
                    }
                });
                (values[term] = values[term] || []).push(valueData);
            });
        });
        valuesInput.val(isFilled ? JSON.stringify(values) : '').trigger('change');
    };

    picker.on('change', function() {
        const term = picker.val();
        if (!term) {
            return;
        }
        const field = makeField(term, picker.find('option:selected').text());
        addValue(field, 'literal');
        picker.val('').trigger('chosen:updated');
    });

    properties
        .on('click', '.add-value', function(e) {
            e.preventDefault();
            addValue($(this).closest('.resource-property'), $(this).data('type'));
        })
        .on('change', '.bulkedit-add-value', function() {
            if (this.value) {
                addValue($(this).closest('.resource-property'), this.value);
                $(this).val('').trigger('chosen:updated');
            }
        })
        .on('click', 'a.remove-value', function(e) {
            e.preventDefault();
            const field = $(this).closest('.resource-property');
            $(this).closest('.value').remove();
            if (!field.find('.values > .value').length) {
                field.remove();
            }
            updateValues();
        })
        .on('click', 'a.value-language', function(e) {
            e.preventDefault();
            const languageWrapper = $(this).closest('.language-wrapper').toggleClass('active');
            if (languageWrapper.hasClass('active')) {
                languageWrapper.find('input.value-language').focus();
            }
        })
        .on('click', '.o-icon-more', function(e) {
            e.preventDefault();
            $(this).parent('.more-actions').toggleClass('active');
        })
        .on('keyup change', 'input.value-language', function() {
            this.setCustomValidity('' === this.value || Omeka.langIsValid(this.value)
                ? ''
                : Omeka.jsTranslate('Please enter a valid language tag'));
        })
        .on('input change', ':input[data-value-key]', updateValues);

    // The visibility is toggled by admin.js on #content, so update after it.
    $(document).on('click', '.bulkedit-create-values a.value-visibility', updateValues);

    // Some data types fill their inputs without event.
    $('#content form').on('submit', updateValues);
});
