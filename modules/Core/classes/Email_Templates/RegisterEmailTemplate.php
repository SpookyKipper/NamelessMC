<?php

class RegisterEmailTemplate extends EmailTemplate
{
    public const ID = 1;

    public function __construct(string $code)
    {
        $link = URL::getSelfURL() . ltrim(URL::build('/complete_signup/', 'c=' . urlencode($code)), '/');

        $this->addPlaceholder('[Link]', $link);
        $this->addPlaceholder('[Message]', new LanguageKey('emails', 'register_message'));

        parent::__construct();
    }

    public function id(): int
    {
        return self::ID;
    }

    public function subject(): LanguageKey
    {
        return new LanguageKey('emails', 'register_subject');
    }
}
