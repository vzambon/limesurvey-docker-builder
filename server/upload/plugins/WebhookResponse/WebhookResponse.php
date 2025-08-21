<?php

class WebhookResponse extends PluginBase
{
    protected $storage = 'DbStorage';

    protected static $description = 'This plugin sends a POST request to a specified URL after a survey response is submitted';
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

        $settings = $this->settings;
    
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
        if($this->get('isActive', 'Survey', $this->getEvent()->get('surveyId')) !== true) {
            return;
        }

        $event = $this->getEvent();

        $surveyId = (int) $event->get('surveyId');
        $responseId = (int) $event->get('responseId');
        $survey = Survey::model()->findByPk($surveyId);

        Yii::app()->loadHelper('admin/exportresults');
        if (!tableExists($survey->responsesTableName)) {
            return array('status' => 'No Data, survey table does not exist.');
        }
        if (!($maxId = SurveyDynamic::model($surveyId)->getMaxId(null, true))) {
            return array('status' => 'No Data, could not get max id.');
        }
        if (!empty($sLanguageCode) && !in_array($sLanguageCode, $survey->getAllLanguages())) {
            return array('status' => 'Language code not found for this survey.');
        }   

        $aFields = array_keys(createFieldMap($survey, 'full', true, false, $survey->language));

        $oFormattingOptions = new FormattingOptions();
        $oFormattingOptions->selectedColumns = $aFields;
        $oFormattingOptions->headingFormat = 'full';
        $oFormattingOptions->answerFormat = 'long';
        $oFormattingOptions->responseMinRecord = $responseId;
        $oFormattingOptions->responseMaxRecord = $responseId;

        $oExport = new ExportSurveyResultsService();
        $sTempFile = $oExport->exportResponses($surveyId, $survey->language, 'json', $oFormattingOptions, '');

        $json = base64_decode((new BigFile($sTempFile, true))->getContent());

        $webhookUrl = $this->get('webhookUrl', 'Survey', $surveyId);
        $token = $this->get('authToken', 'Survey', $surveyId);

        $webhookUrl = $webhookUrl . '?token=' . $token;

        $this->httpPost($webhookUrl, $json);

        $this->debug($webhookUrl, $json);

        return;
    }

    private function httpPost($url, $body)
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_HEADER => [
                'Content-Type: application/json',
                'Accept: application/json;charset=UTF8',
            ],
            CURLOPT_FAILONERROR => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
        ]);

        curl_exec($ch);
        $errNo = curl_errno($ch);
        $errMsg = curl_error($ch);
        curl_close($ch);

        if ($errNo !== 0) {
            throw new Exception("CURL Error ($errNo): $errMsg");
        }
    }
    private function debug($webhookUrl, $json)
    {
        $html =  '<pre>';
        $html .= print_r($webhookUrl, true);
        $html .=  "<br><br> ----------------------------- <br><br>";
        $html .= print_r($json, true);
        $html .=  "<br><br> ----------------------------- <br><br>";
        $html .=  '</pre>';
        $event = $this->getEvent();
        $event->getContent($this)->addContent($html);
    }
}
