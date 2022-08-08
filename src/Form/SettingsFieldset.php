<?php declare(strict_types=1);

namespace BulkEdit\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;

class SettingsFieldset extends Fieldset
{
    protected $label = 'Bulk Edit'; // @translate

    public function init(): void
    {
        $this
            ->setAttribute('id', 'bulk-edit')
            ->add([
                'name' => 'bulkedit_deduplicate_on_save',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Deduplicate values on save', // @translate
                ],
                'attributes' => [
                    'id' => 'bulkedit_deduplicate_on_save',
                ],
            ])
        ;
    }
}
