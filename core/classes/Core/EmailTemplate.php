<?php

class EmailTemplate
{
    /**
     * @var array<string, string> Placeholders for email templates
     */
    private static array $_message_placeholders = [];

    public function __construct(
        private string $name,
    ) {
        $file = implode(DIRECTORY_SEPARATOR, [ROOT_PATH, 'custom', 'templates', TEMPLATE, 'email', $name . '.html']);

        if (!file_exists($file)) {
            throw new InvalidArgumentException("Email template file $file does not exist");
        }
    }

    public function render(Language $language, array $variables = []): string
    {
        return self::formatEmail(
            $this->name,
            $language,
            $variables
        );
    }

    /**
     * Add a custom placeholder/variable for email messages.
     *
     * @param string                                   $key   The key to use for the placeholder, should be enclosed in square brackets.
     * @param string|Closure(Language, string): string $value The value to replace the placeholder with.
     */
    public static function addPlaceholder(string $key, $value): void
    {
        self::$_message_placeholders[$key] = $value;
    }

    /**
     * Format an email template and replace placeholders.
     *
     * @param  string   $email            Name of email to format.
     * @param  Language $viewing_language Instance of Language class to use for translations.
     * @return string   Formatted email.
     */
    public static function formatEmail(string $email, Language $viewing_language): string
    {
        $placeholders = array_keys(self::$_message_placeholders);

        $placeholder_values = [];
        foreach (self::$_message_placeholders as $value) {
            if (is_callable($value)) {
                $placeholder_values[] = $value($viewing_language, $email);
            } else {
                $placeholder_values[] = $value;
            }
        }

        return str_replace(
            $placeholders,
            $placeholder_values,
            file_get_contents(implode(DIRECTORY_SEPARATOR, [ROOT_PATH, 'custom', 'templates', TEMPLATE, 'email', $email . '.html']))
        );
    }
}
