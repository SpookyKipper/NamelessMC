<?php
/**
 * Notification class to handle sending notifications to a user or users
 * Notifications can be alerts or emails
 *
 * @package NamelessMC\Core
 * @author Samerton
 * @version 2.2.0
 * @license MIT
 */

class Notification {

    private AlertTemplate $_alertTemplate;
    private EmailTemplate $_emailTemplate;
    private int $_authorId;
    private array $_recipients = [];
    private string $_type;

    private static array $_types = [];

    /**
     * Instantiate a new notification
     *
     * @param string $type Type of notification
     * @param string|LanguageKey $title Title of notification
     * @param string|LanguageKey $content Notification content. For alerts, if $alertUrl is set, this will ignored. If $alertUrl is not set, this will be the content of the alert. This will always be the content of the email.
     * @param int|int[] $recipients Notification recipient or recipients - array of user IDs
     * @param int       $authorId        User ID that sent the notification
     * @param ?callable $contentCallback Optional callback to perform for each recipient's content
     * @param bool      $skipPurify      Whether to skip content purifying, default false
     * @param ?string $alertUrl        Optional URL to link to when clicking the alert
     *
     * @throws NotificationTypeNotFoundException
     */
    public function __construct(
        string $type,
        AlertTemplate $alertTemplate,
        EmailTemplate $emailTemplate,
        int|array $recipients,
        int $authorId,
    ) {
        if (!in_array($type, array_column(self::getTypes(), 'key'))) {
            throw new NotificationTypeNotFoundException("Type $type not registered");
        }

        $this->_type = $type;
        $this->_alertTemplate = $alertTemplate;
        $this->_emailTemplate = $emailTemplate;
        $this->_authorId = $authorId;

        if (!is_array($recipients)) {
            $recipients = [$recipients];
        }

        if (count($recipients) === 0) {
            return;
        }

        $languageCodes = DB::getInstance()->query(
            'SELECT nl2_users.id, COALESCE(nl2_languages.short_code, NULL) AS `short_code` FROM nl2_users LEFT JOIN nl2_languages ON nl2_languages.id = nl2_users.language_id WHERE nl2_users.id IN (' . implode(',', array_map(static fn ($_) => '?', $recipients)) . ')',
            $recipients
        )->results();
        $languageCodes = array_column($languageCodes, 'short_code', 'id');

        $this->_recipients = array_map(static function ($recipientId) use ($languageCodes) {
            return [
                'id' => $recipientId,
                'language_code' => $languageCodes[$recipientId] ?? DEFAULT_LANGUAGE,
            ];
        }, $recipients);
    }

    public function send(): void {
        /** @var array $recipient */
        foreach ($this->_recipients as $recipient) {
            $userId = $recipient['id'];
            $languageCode = $recipient['language_code'];

            $preferences = DB::getInstance()->query(
                <<<SQL
                    SELECT `alert`, `email`
                    FROM nl2_users_notification_preferences
                    WHERE `type` = ? AND `user_id` = ?
                SQL,
                [$this->_type, $userId]
            )->first();

            if ($preferences->alert) {
                $this->sendAlert($userId, $languageCode);
            }
            if ($preferences->email) {
                $this->sendEmail($userId, $languageCode);
            }
        }
    }

    private function sendAlert(int $userId, string $languageCode): void {
        Alert::send(
            $userId,
            $this->_alertTemplate->title->translate($languageCode),
            // if the alert has a link set, we don't want to send the content as the alert content
            $this->_alertTemplate->link ? null : $this->_alertTemplate->content->translate($languageCode),
            $this->_alertTemplate->link,
        );
    }

    private function sendEmail(int $userId, string $languageCode): void {
        $task = (new SendEmail())->fromNew(
            Module::getIdFromName('Core'),
            'Send Email Notification',
            [
                'subject' => $this->_emailTemplate->subject()->translate($languageCode),
                'content' => $this->_emailTemplate->renderContent($languageCode),
            ],
            date('U'), // TODO: schedule a date/time?
            'User',
            $userId,
            false,
            null,
            $this->_authorId
        );

        Queue::schedule($task);
    }

    /**
     * Register a custom notification type
     * @param string $type
     * @param string $value              Human-readable
     * @param int    $moduleId
     * @param array  $defaultPreferences Set of default preferences in form preferenceKey => true/false
     * @return void
     */
    public static function addType(string $type, string $value, int $moduleId, array $defaultPreferences = []): void {
        self::$_types[] = [
            'key' => $type,
            'value' => $value,
            'module' => $moduleId,
            'defaultPreferences' => $defaultPreferences
        ];
    }

    /**
     * Returns all registered notification types
     * @return array
     */
    public static function getTypes(): array {
        return self::$_types;
    }
}
