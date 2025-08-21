<?php
/**
 * InstallUploadedPlugin Command
 *
 * This command installs a plugin from the upload directory.
 * Usage: php console.php installUploadedPlugin --pluginName=<pluginName>
 */
class InstallUploadedPluginCommand extends CConsoleCommand
{
    public function actionIndex($pluginName)
    {
        $pm = Yii::app()->getPluginManager();
        $plugin = Plugin::model()->findByAttributes(['name' => $pluginName]);

        $pluginDir = Yii::app()->getConfig("rootdir") . "/upload/plugins/{$pluginName}";

        if (!$plugin) {
            $pm->installUploadedPlugin($pluginDir);
            echo "Plugin {$pluginName} installed.\n";
        } else {
            echo "Plugin {$pluginName} already installed.\n";
        }

        #activate the plugin
        if (!$pm->isPluginActive($pluginName)) {
            $plugin->active = 1;
            $plugin->save();
            echo "Plugin {$pluginName} activated.\n";
        }
    }
}
