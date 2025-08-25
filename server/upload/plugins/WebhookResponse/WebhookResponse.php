<?php

class WebhookResponse extends PluginBase
{
    protected $storage = 'DbStorage';

    protected static $description = 'This plugin sends a POST request to a specified URL after a survey response is ' .
        'submitted';
    protected static $name = 'WebhookResponse';

    protected $settings = [
        'useAlways' => [
            'global_only' => true,
            'type' => 'select',
            'options' => [
                false => 'No',
                true => 'Yes'
            ],
            'default' => true,
            'label' => 'Send a hook for every survey by default ?',
        ],
        'webhookUrl' => [
            'type' => 'string',
            'label' => 'The target webhook URL',
            'help' => 'The URL to which the webhook will send data after a survey response is submitted.'
        ],
        'authToken' => [
            'type' => 'string',
            'label' => 'Webhook API Authorization Token',
            'help' => 'Webhook Authotization Token'
        ]
    ];

    /** Configure your webhook URL in plugin settings */
    public function init()
    {
        $this->subscribe('newSurveySettings');
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('afterSurveyComplete');
        
        $this->settings['useAlways']['default'] = getenv('ACTIVATE_WEBHOOK_PLUGIN') === 'true';
        $this->settings['webhookUrl']['default'] = getenv('WEBHOOK_URL') ?: 'http://localhost/webhook';
        $this->settings['authToken']['default'] = getenv('WEBHOOK_AUTH_TOKEN') ?: '<token>';

        $this->setDefaults();
    }

    /**
     * Render the survey settings form
     */
    public function beforeSurveySettings()
    {
        $event = $this->getEvent();
        $surveyId = $event->get('survey');
        $globalSettings = $this->getPluginSettings();
    
        $settings = $this->getDefaultSurveySettings();

        foreach ($settings as $name => &$setting) {
            $value = $this->get($name, 'Survey', $surveyId);

            if (is_null($value) && (($setting['nullable'] ?? false) === false)) {
                $value = $globalSettings[$name]['current'] ??
                    $globalSettings[$name]['default'] ??
                    $setting['default'] ?? null;
            }

            $setting['current'] = $value;
        }

        $event->set("surveysettings.{$this->id}", [
            'name' => get_class($this),
            'settings' => $settings,
        ]);
    }

    /**
    * Save the settings
    */
    public function newSurveySettings()
    {
        $event = $this->event;
        $survey = $event->get('survey');
        $settings = $event->get('settings');

        $this->setSettings($survey, $settings);
    }

    public function afterSurveyComplete()
    {
        $event = $this->getEvent();
        $surveyId   = $event->get('surveyId');
        $responseId = $event->get('responseId');
        $response = $this->api->getResponse($surveyId, $responseId);
        $language = $response['startlanguage'] ?? 'en';
        $questions = $this->api->getQuestions($surveyId, $language);
        $isActive = (bool) $this->get('isActive', 'Survey', $surveyId) ||
            (bool) $this->getPluginSettings()['useAlways']['current'];
        
        if(!$isActive) {
            var_dump('Plugin is inactive for this survey');
            return;
        }

        $questionsMap = [];
        foreach ($questions as $question) {
            $questionText = $question->questionl10ns[$language]->question;

            switch($question->type){
                case 'T':
                case 'S':
                    $answerValue = $response[$question->title];
                    break;
                case 'L':
                    $answerCode = $response[$question->title];
                    $answerObj = null;
                    foreach ($question->answers as $ans) {
                        if ($ans->code == $answerCode) {
                            $answerObj = $ans;
                            break;
                        }
                    }
                    $answerValue = $answerObj
                        ? ($answerObj->answerl10ns[$language]->answer ?? $answerCode)
                        : $answerCode;
                    break;
                default:
                    $answerValue = $response[$question->title] ?? null;
                    break;
            }

            $questionsMap[$question->qid] = [
                'question' => $questionText,
                'code' => $question->title,
                'answer' => $answerValue
            ];
        }

        $token = $this->api->getToken($surveyId, $response['token']);

        $response['tid'] = $token->tid;
        $response['participant_id'] = $token->participant_id;
        $response['surveyId'] = $surveyId;

        $webhookUrl = $this->get('webhookUrl', 'Survey', $surveyId) ??
            $this->getPluginSettings()['webhookUrl']['current'];
        $token = $this->get('authToken', 'Survey', $surveyId) ??
            $this->getPluginSettings()['authToken']['current'];

        $webhookUrl = $webhookUrl . '?token=' . $token;

        $response = array_merge($response, ['map' => $questionsMap]);

        var_dump($response);

        $this->httpPost($webhookUrl, $response);
    }

    private function httpPost($url, $body)
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json;charset=UTF-8',
            ],
            CURLOPT_FAILONERROR => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $result = curl_exec($ch);
        $errNo = curl_errno($ch);
        $errMsg = curl_error($ch);
        curl_close($ch);

        if ($errNo !== 0) {
            echo "CURL Error ($errNo): $errMsg";
        }

        return $result;
    }

    private function getDefaultSurveySettings()
    {
        $event = $this->getEvent();
        $settingsWithoutGlobal = array_filter($this->settings, function ($s) {
            return empty($s['global_only']);
        });

        return array_merge([
            'isActive' => [
                'type' => 'boolean',
                'default' => !$event->get('useAlways', 'Global') ? false : true,
                'label' => 'Is the plugin active?',
                'help' => 'Enable or disable the webhook functionality.'
            ]
        ], $settingsWithoutGlobal);
    }

    private function setSettings($survey, $settings) {
        $globalSettings = $this->getPluginSettings();

        foreach ($settings as $name => $value)
        {
            $value = $value ?? $globalSettings[$name]['current'] ?? $this->settings[$name]['default'] ?? null;
            $this->set($name, $value, 'Survey', $survey);
        }
    }

    private function setDefaults()
    {
        $event = $this->event;

        if(!$event) {
            return;
        }
        
        $survey = $event?->get('survey') ?? null;
        if($survey === null) {
            return;
        }

        $settings = $this->getDefaultSurveySettings();

        $this->setSettings($survey, $settings);
    }
}
