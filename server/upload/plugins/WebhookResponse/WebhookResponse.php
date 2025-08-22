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
                0 => 'No',
                1 => 'Yes'
            ],
            'default' => 0,
            'label' => 'Send a hook for every survey by default ?',
        ],
        'webhookUrl' => [
            'type' => 'string',
            'default' => '',
            'label' => 'The target webhook URL',
            'help' => 'The URL to which the webhook will send data after a survey response is submitted.'
        ],
        'authToken' => [
            'nullable' => true,
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
    }

    /**
     * Render the survey settings form
     */
    public function beforeSurveySettings()
    {
        $event = $this->getEvent();
        $surveyId = $event->get('survey');
    
        $settingsWithoutGlobal = array_filter($this->settings, function ($s) {
            return empty($s['global_only']);
        });

        // exemplo de setting adicional fixo
        $settings = array_merge([
            'isActive' => [
                'type' => 'boolean',
                'default' => 0,
                'label' => 'Is the plugin active?',
                'help' => 'Enable or disable the webhook functionality.'
            ]
        ], $settingsWithoutGlobal);

        $globalSettings = $this->getPluginSettings();

        foreach ($settings as $name => &$setting) {
            $value = $this->get($name, 'Survey', $surveyId);

            if (is_null($value) && (($setting['nullable'] ?? false) === false)) {
                $value = $globalSettings[$name]['current'] ?? null;
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
        
        $globalSettings = $this->getPluginSettings();

        foreach ($event->get('settings') as $name => $value)
        {
            $value = $value ?? $globalSettings[$name]['current'] ?? $this->settings[$name]['default'] ?? null;
            $this->set($name, $value, 'Survey', $event->get('survey'));
        }
    }

    public function afterSurveyComplete()
    {
        $isActive = (bool) $this->get('isActive', 'Survey', $this->getEvent()->get('surveyId'));

        if(!$isActive) {
            return;
        }

        $event = $this->getEvent();
        $surveyId   = $event->get('surveyId');
        $responseId = $event->get('responseId');
        $participantId = $event->get('participantId');
        $language = 'en';

        $response = $this->api->getResponse($surveyId, $responseId);
        $questions = $this->api->getQuestions($surveyId, $language);

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
                'text' => $questionText,
                'code' => $question->title,
                'answer' => $answerValue
            ];
        }

        $token = $this->api->getToken($surveyId, $response['token']);

        $response['tid'] = $token->tid;
        $response['participant_id'] = $token->participant_id;
        $response['surveyId'] = $surveyId;

        $webhookUrl = $this->get('webhookUrl', 'Survey', $surveyId);
        $token = $this->get('authToken', 'Survey', $surveyId);

        $webhookUrl = $webhookUrl . '?token=' . $token;

        $response = array_merge($response, ['map' => $questionsMap]);

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
            throw new \Exception("CURL Error ($errNo): $errMsg");
        }

        return $result;
    }
}
