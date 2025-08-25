<?php

/**
 * InstallUploadedTheme Command
 *
 * This command installs a theme from the upload directory and sets it as the default theme.
 * Usage: php console.php installuploadedtheme --theme=<theme-name>
 */
class InstallUploadedThemeCommand extends CConsoleCommand
{
    /**
     * Instala um tema existente em upload/themes/survey e define como default
     * @param string $theme Nome da pasta do tema
     */
    public function actionIndex($theme = null)
    {
        if (!$theme) {
            echo("Use: php console.php installuploadedtheme --theme=<theme-name>\n");
            return;
        }

        $themePath = Yii::app()->basePath . "/../upload/themes/survey/{$theme}";
        echo("Installing uploaded theme '{$theme}'...\nPath: {$themePath}\n");

        if (!is_dir($themePath)) {
            echo("Error: '$themePath' not found.\n");
            return;
        }

        $this->registerTheme($theme);
        $this->deployThemeFiles($theme, $themePath);
        Yii::app()->setConfig('defaulttheme', $theme);
        echo("Theme '{$theme}' defined as global theme.\n");

        try {
            $manifest = $this->loadManifest($themePath);
            $aDatas = $this->parseManifest($manifest, $theme);
            self::importManifest($theme, $aDatas);
            echo("Theme '{$theme}' imported successfully.\n");
        } catch (Exception $e) {
            echo("Error importing theme manifest: {$e->getMessage()}\n{$e->getTraceAsString()}\n");
        }
    }

    private function registerTheme(string $themeName): void
    {
        $theme = TemplateConfiguration::model()->findByAttributes([
            'template_name' => $themeName,
            'sid' => null,
            'gsid' => 1,
        ]);

        if (!$theme) {
            $theme = new TemplateConfiguration();
            $theme->template_name = $themeName;
            $theme->sid = null;
            $theme->gsid = 1;
            $theme->uid = 1;

            if ($theme->save(false)) {
                echo("Theme '{$themeName}' registered successfully.\n");
            } else {
                throw new Exception("Failed to register theme: " . json_encode($theme->getErrors()));
            }
        } else {
            echo("Theme '{$themeName}' already registered.\n");
        }
    }

    private function loadManifest(string $themePath): SimpleXMLElement
    {
        $manifestPath = $themePath . '/config.xml';
        if (!file_exists($manifestPath)) {
            throw new Exception("Manifest file not found at '$manifestPath'.");
        }

        $manifestData = simplexml_load_file($manifestPath);
        if (!$manifestData) {
            throw new Exception("Failed to parse manifest file at '$manifestPath'.");
        }

        return $manifestData;
    }

    private function parseManifest(SimpleXMLElement $manifest, string $themeName): array
    {
        $getFiles = fn($node) => isset($node->add) ? array_map('strval', iterator_to_array($node->add)) : [];

        return [
            'title' => (string) ($manifest->metadata->title ?? $themeName),
            'description' => (string) ($manifest->metadata->description ?? 'No description provided'),
            'extends' => isset($manifest->metadata->extends) ? (string) $manifest->metadata->extends : null,
            'api_version' => (string) ($manifest->metadata->apiVersion ?? ''),
            'author' => (string) ($manifest->metadata->author ?? ''),

            'files_css' => $getFiles($manifest->files->css ?? new SimpleXMLElement('<root/>')),
            'files_js' => $getFiles($manifest->files->js ?? new SimpleXMLElement('<root/>')),
            'files_print_css' => $getFiles($manifest->files->print_css ?? new SimpleXMLElement('<root/>')),

            'cssframework_name' => (string) ($manifest->engine->cssframework->name ?? ''),
            'cssframework_css' => $getFiles($manifest->engine->cssframework_css ?? new SimpleXMLElement('<root/>')),
            'cssframework_js' => $getFiles($manifest->engine->cssframework_js ?? new SimpleXMLElement('<root/>')),

            'view_folder' => (string) ($manifest->engine->viewdirectory ?? ''),
            'files_folder' => (string) ($manifest->engine->filesdirectory ?? ''),
            'packages_to_load' => $getFiles($manifest->engine->packages ?? new SimpleXMLElement('<root/>')),

            'aOptions' => isset($manifest->options) ? json_decode(json_encode($manifest->options), true) : []
        ];
    }

    /**
     * Create a new entry in {{templates}} and {{template_configuration}} table using the template manifest
     * @param string $sTemplateName the name of the template to import
     * @param array $aDatas
     * @return boolean true on success | exception
     * @throws Exception|InvalidArgumentException
     */
    public static function importManifest($sTemplateName, $aDatas)
    {
        if (empty($aDatas)) {
            throw new InvalidArgumentException('$aDatas cannot be empty');
        }

        $oNewTemplate                   = new Template();
        $oNewTemplate->name             = $sTemplateName;
        $oNewTemplate->folder           = $sTemplateName;
        $oNewTemplate->title            = $aDatas['title'];
        $oNewTemplate->creation_date    = date("Y-m-d H:i:s");
        $oNewTemplate->author           = 'Protiviti';
        $oNewTemplate->author_email     = '';
        $oNewTemplate->author_url       = '';
        $oNewTemplate->api_version      = $aDatas['api_version'];
        $oNewTemplate->view_folder      = $aDatas['view_folder'];
        $oNewTemplate->files_folder     = $aDatas['files_folder'];
        $oNewTemplate->description      = $aDatas['description'];
        $oNewTemplate->owner_id         = 1;
        $oNewTemplate->extends          = $aDatas['extends'];

        if ($oNewTemplate->save(false)) {
            $oNewTemplateConfiguration                  = new TemplateConfiguration();
            $oNewTemplateConfiguration->template_name   = $sTemplateName;

            // Those ones are only filled when importing manifest from upload directory

            $oNewTemplateConfiguration->files_css         = self::formatToJsonArray($aDatas['files_css']);
            $oNewTemplateConfiguration->files_js          = self::formatToJsonArray($aDatas['files_js']);
            $oNewTemplateConfiguration->files_print_css   = self::formatToJsonArray($aDatas['files_print_css']);
            $oNewTemplateConfiguration->cssframework_name = $aDatas['cssframework_name'];
            $oNewTemplateConfiguration->cssframework_css  = self::formatToJsonArray($aDatas['cssframework_css']);
            $oNewTemplateConfiguration->cssframework_js   = self::formatToJsonArray($aDatas['cssframework_js']);
            $oNewTemplateConfiguration->options           = self::convertOptionsToJson($aDatas['aOptions']);
            $oNewTemplateConfiguration->packages_to_load  = self::formatToJsonArray($aDatas['packages_to_load']);


            if ($oNewTemplateConfiguration->save(false)) {
                // Find all surveys using this theme (if reinstalling) and create an entry on db for them
                $aSurveysUsingThisTeme  =  Survey::model()->findAll(
                    'template=:template',
                    array(':template' => $sTemplateName)
                );
                foreach ($aSurveysUsingThisTeme as $oSurvey) {
                     TemplateConfiguration::checkAndcreateSurveyConfig($oSurvey->sid);
                }

                return true;
            } else {
                throw new Exception(json_encode($oNewTemplateConfiguration->getErrors()));
            }
        } else {
            throw new Exception(json_encode($oNewTemplate->getErrors()));
        }
    }

     /**
     * Convert the values to a json.
     * It checks that the correct values is inserted.
     * @param array|object $oFiled the filed to convert
     * @param boolean $bConvertEmptyToString formats empty values as empty strings instead of objects.
     * @return string  json
     */
    public static function formatToJsonArray($oFiled, $bConvertEmptyToString = false)
    {
        if ($bConvertEmptyToString) {
            foreach ($oFiled as $option => $optionValue) {
                // clean every value from newlines, tabs and blank spaces for options
                $oFiled->$option = trim(preg_replace('/[ \t]+/', ' ', preg_replace('/\s*$^\s*/m', "", $optionValue)));
            }
        }
        // encode then decode will convert the SimpleXML to a normal object
        $jFiled = json_encode($oFiled);
        $oFiled = json_decode($jFiled);

        // If in template manifest, a single file is provided, a string is produced instead of an array.
        // We force it to array here

        foreach (array('add', 'replace', 'remove') as $sAction) {
            if (is_object($oFiled) && !empty($oFiled->$sAction) && is_string($oFiled->$sAction)) {
                $sValue      = $oFiled->$sAction;
                $oFiled->$sAction = array($sValue);
                $jFiled      = json_encode($oFiled);
            }
        }
        // Converts empty objects to empty strings
        if ($bConvertEmptyToString) {
            $jFiled = str_replace('{}', '""', $jFiled);
        }
        return $jFiled;
    }

    /**
     * Extracts option values from theme options node (XML) into a json key-value map.
     * Inner nodes (which maybe inside each option element) are ignored.
     * Option values are trimmed as they may contain undesired new lines in the XML document.
     * @param array|object $options the filed to convert
     * @return string  json
     */
    public static function convertOptionsToJson($options)
    {
        $optionsArray = [];
        foreach ($options as $option => $optionValue) {
            // Ensure optionValue is cast to string to avoid array-to-string conversion errors
            $optionsArray[$option] = is_array($optionValue) ? json_encode($optionValue) : trim((string) $optionValue);
        }
        if (empty($optionsArray)) {
            return '""';
        }
        return json_encode($optionsArray);
    }

    private function deployThemeFiles(string $themeName, string $sourcePath): void
    {
        $destPath = Yii::app()->basePath . '/../themes/survey/' . $themeName;

        if (is_dir($destPath)) {
            echo "Theme folder already exists at '{$destPath}', skipping deployment.\n";
            return;
        }

        echo "Copying theme files from '{$sourcePath}' to '{$destPath}'...\n";
        $this->recursiveCopy($sourcePath, $destPath);
        echo "Theme files copied successfully.\n";
    }

    private function recursiveCopy(string $src, string $dst): void
    {
        $dir = opendir($src);
        @mkdir($dst, 0755, true);
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;
            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;
            if (is_dir($srcPath)) {
                $this->recursiveCopy($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
        closedir($dir);
    }

}
