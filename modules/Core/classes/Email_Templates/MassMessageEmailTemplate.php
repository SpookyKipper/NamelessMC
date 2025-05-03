<?php

class MassMessageEmailTemplate extends EmailTemplate
{
    public function __construct(string $content)
    {
        $this->addPlaceholder('[Message]', $content);

        parent::__construct();
    }

    public function subject(): LanguageKey
    {
        return new LanguageKey('admin', 'mass_message');
    }
}
