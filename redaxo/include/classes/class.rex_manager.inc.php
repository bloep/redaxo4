<?php

/**
 * Managerklasse zum handeln von rexAddons
 */
/*abstract*/ class rex_baseManager
{
    var $i18nPrefix;

    /**
     * Konstruktor
     *
     * @param $i18nPrefix Sprachprefix aller I18N Sprachschlüssel
     */

    // this is the new style constructor used by newer php versions.
    // important: if you change the signatur of this method, change also the signature of rex_baseManager()
    function __construct($i18nPrefix)
    {
        $this->rex_baseManager($i18nPrefix);
    }

    // this is the deprecated old style constructor kept for compat reasons. 
    // important: if you change the signatur of this method, change also the signature of __construct()
    function rex_baseManager($i18nPrefix)
    {
        $this->i18nPrefix = $i18nPrefix;
    }

    /**
     * Installiert ein Addon
     *
     * @param $addonName Name des Addons
     * @param $installDump Flag, ob die Datei install.sql importiert werden soll
     */
    /*public*/ function install($addonName, $installDump = true)
    {
        global $REX;

        $state = true;

        $install_dir  = $this->baseFolder($addonName);
        $install_file = $install_dir . 'install.inc.php';
        $install_sql  = $install_dir . 'install.sql';
        $config_file  = $install_dir . 'config.inc.php';
        $files_dir    = $install_dir . 'files';

        // Prüfen des Addon Ornders auf Schreibrechte,
        // damit das Addon später wieder gelöscht werden kann
        $state = rex_is_writable($install_dir);

        if ($state === true) {
            if (is_readable($install_file)) {
                // save state of $REX variable for later use with getLastInstalledAddonName()
                $rexAddonBeforeInstall = $REX['ADDON']['install'];

                // include install file
                $this->includeInstaller($addonName, $install_file);

                // Wurde das "install" Flag gesetzt?
                // Fehlermeldung ausgegeben? Wenn ja, Abbruch
                $instmsg = $this->apiCall('getProperty', array($addonName, 'installmsg', ''));

                if (!$this->apiCall('isInstalled', array($addonName)) || $instmsg) {
                    $state = $this->I18N('no_install', $addonName) . '<br />';
                    if ($instmsg == '') {
                        // check if there is a mismatch between addon name and addon directory name
                        $lastInstalledAddonName = $this->getLastInstalledAddonName($rexAddonBeforeInstall);

                        if ($lastInstalledAddonName != '' && $lastInstalledAddonName != $addonName) {
                            $state .= $this->I18N('name_mismatch', $lastInstalledAddonName);
                        } else {
                            $state .= $this->I18N('no_reason');
                        }
                    } else {
                        $state .= $instmsg;
                    }
                } else {
                    // check if config file exists
                    if (is_readable($config_file)) {
                        if (!$this->apiCall('isActivated', array($addonName))) {
                            $this->includeConfig($addonName, $config_file);
                        }
                    } else {
                        $state = $this->I18N('config_not_found');
                    }

                    if ($installDump === true && $state === true && is_readable($install_sql)) {
                        $state = rex_install_dump($install_sql);

                        if ($state !== true) {
                            $state = 'Error found in install.sql:<br />' . $state;
                        }
                    }

                    // Installation ok
                    if ($state === true) {
                        // regenerate Addons file
                        $state = $this->generateConfig();
                    }
                }
            } else {
                $state = $this->I18N('install_not_found');
            }
        }

        // Dateien kopieren
        if ($state === true && is_dir($files_dir)) {
            if (!rex_copyDir($files_dir, $this->mediaFolder($addonName), $REX['MEDIAFOLDER'])) {
                $state = $this->I18N('install_cant_copy_files');
            }
        }

        if ($state !== true) {
            $this->apiCall('setProperty', array($addonName, 'install', 0));
        }

        return $state;
    }

    /**
     * De-installiert ein Addon
     *
     * @param $addonName Name des Addons
     */
    /*public*/ function uninstall($addonName)
    {
        $state = true;

        $install_dir    = $this->baseFolder($addonName);
        $uninstall_file = $install_dir . 'uninstall.inc.php';
        $uninstall_sql  = $install_dir . 'uninstall.sql';

        if (is_readable($uninstall_file)) {
            $this->includeUninstaller($addonName, $uninstall_file);

            // Wurde das "install" Flag gesetzt?
            // Fehlermeldung ausgegeben? Wenn ja, Abbruch
            $instmsg = $this->apiCall('getProperty', array($addonName, 'installmsg', ''));

            if ($this->apiCall('isInstalled', array($addonName)) || $instmsg) {
                $state = $this->I18N('no_uninstall', $addonName) . '<br />';
                if ($instmsg == '') {
                    $state .= $this->I18N('no_reason');
                } else {
                    $state .= $instmsg;
                }
            } else {
                $state = $this->deactivate($addonName);

                if ($state === true && is_readable($uninstall_sql)) {
                    $state = rex_install_dump($uninstall_sql);

                    if ($state !== true) {
                        $state = 'Error found in uninstall.sql:<br />' . $state;
                    }
                }

                if ($state === true) {
                    // regenerate Addons file
                    $state = $this->generateConfig();
                }
            }
        } else {
            $state = $this->I18N('uninstall_not_found');
        }

        $mediaFolder = $this->mediaFolder($addonName);
        if ($state === true && is_dir($mediaFolder)) {
            if (!rex_deleteDir($mediaFolder, true)) {
                $state = $this->I18N('install_cant_delete_files');
            }
        }

        // Fehler beim uninstall -> Addon bleibt installiert
        if ($state !== true) {
            $this->apiCall('setProperty', array($addonName, 'install', 1));
        }

        return $state;
    }

    /**
     * Aktiviert ein Addon
     *
     * @param $addonName Name des Addons
     */
    /*public*/ function activate($addonName)
    {
        if ($this->apiCall('isInstalled', array($addonName))) {
            $this->apiCall('setProperty', array($addonName, 'status', 1));
            $state = $this->generateConfig();
        } else {
            $state = $this->I18N('no_activation', $addonName);
        }

        if ($state !== true) {
            $this->apiCall('setProperty', array($addonName, 'status', 0));
        }

        return $state;
    }

    /**
     * Deaktiviert ein Addon
     *
     * @param $addonName Name des Addons
     */
    /*public*/ function deactivate($addonName)
    {
        $this->apiCall('setProperty', array($addonName, 'status', 0));
        $state = $this->generateConfig();

        if ($state !== true) {
            $this->apiCall('setProperty', array($addonName, 'status', 1));
        }

        return $state;
    }

    /**
     * Löscht ein Addon im Filesystem
     *
     * @param $addonName Name des Addons
     */
    /*public*/ function delete($addonName)
    {
        // zuerst deinstallieren
        // bei erfolg, komplett löschen
        $state = true;
        $state = $state && $this->uninstall($addonName);
        $state = $state && rex_deleteDir($this->baseFolder($addonName), true);
        $state = $state && $this->generateConfig();

        return $state;
    }

    /**
     * Übersetzen eines Sprachschlüssels unter Verwendung des Prefixes
     */
    /*protected*/ function I18N()
    {
        global $I18N;

        $args = func_get_args();
        $args[0] = $this->i18nPrefix . $args[0];

        return rex_call_func(array($I18N, 'msg'), $args, false);
    }

    /**
     * Bindet die config-Datei eines Addons ein
     */
    /*protected*/ function includeConfig($addonName, $configFile)
    {
        trigger_error('Method has to be overridden by subclass!', E_USER_ERROR);
    }

    /**
     * Bindet die installations-Datei eines Addons ein
     */
    /*protected*/ function includeInstaller($addonName, $installFile)
    {
        trigger_error('Method has to be overridden by subclass!', E_USER_ERROR);
    }

    /**
     * Bindet die deinstallations-Datei eines Addons ein
     */
    /*protected*/ function includeUninstaller($addonName, $uninstallFile)
    {
        trigger_error('Method has to be overridden by subclass!', E_USER_ERROR);
    }

    /**
     * Speichert den aktuellen Zustand
     */
    /*protected*/ function generateConfig()
    {
        trigger_error('Method has to be overridden by subclass!', E_USER_ERROR);
    }

    /**
     * Ansprechen einer API funktion
     *
     * @param $method Name der Funktion
     * @param $arguments Array von Parametern/Argumenten
     */
    /*protected*/ function apiCall($method, $arguments)
    {
        trigger_error('Method has to be overridden by subclass!', E_USER_ERROR);
    }

    /**
     * Findet den Basispfad eines Addons
     */
    /*protected*/ function baseFolder($addonName)
    {
        trigger_error('Method has to be overridden by subclass!', E_USER_ERROR);
    }

    /**
     * Findet den Basispfad für Media-Dateien
     */
    /*protected*/ function mediaFolder($addonName)
    {
        trigger_error('Method has to be overridden by subclass!', E_USER_ERROR);
    }

    /**
     * Gibt den Namen des zuletzt installierten Addons aus
     */
    /*public*/ function getLastInstalledAddonName($rexAddonBeforeInstall)
    {
        global $REX;

        if (isset($REX['ADDON']['installmsg'])) {
            $key = key($REX['ADDON']['installmsg']);
        } else {
            $diff = array_diff_key($REX['ADDON']['install'], $rexAddonBeforeInstall);
            $key = key($diff);
        }

        if ($key != null) {
            return $key;
        } else {
            return '';
        }
    }
}

/**
 * Manager zum installieren von OOAddons
 */
class rex_addonManager extends rex_baseManager
{
    var $configArray;

    // this is the new style constructor used by newer php versions.
    // important: if you change the signatur of this method, change also the signature of rex_addonManager()
    function __construct($configArray)
    {
        $this->rex_addonManager($configArray);
    }

    // this is the deprecated old style constructor kept for compat reasons. 
    // important: if you change the signatur of this method, change also the signature of __construct()
    function rex_addonManager($configArray)
    {
        $this->configArray = $configArray;
        parent::rex_baseManager('addon_');
    }

    /*public*/ function delete($addonName)
    {
        global $REX, $I18N;

        // System AddOns dürfen nicht gelöscht werden!
        if (in_array($addonName, $REX['SYSTEM_ADDONS'])) {
            return $I18N->msg('addon_systemaddon_delete_not_allowed');
        }

        return parent::delete($addonName);
    }

    /*protected*/ function includeConfig($addonName, $configFile)
    {
        global $REX, $I18N; // Nötig damit im Addon verfügbar
        require $configFile;
    }


    /*protected*/ function includeInstaller($addonName, $installFile)
    {
        global $REX, $I18N; // Nötig damit im Addon verfügbar
        require $installFile;
    }

    /*protected*/ function includeUninstaller($addonName, $uninstallFile)
    {
        global $REX, $I18N; // Nötig damit im Addon verfügbar
        require $uninstallFile;
    }

    /*protected*/ function generateConfig()
    {
        return rex_generateAddons($this->configArray);
    }

    /*protected*/ function apiCall($method, $arguments)
    {
        if (!is_array($arguments)) {
            trigger_error('Expecting $arguments to be an array!', E_USER_ERROR);
        }

        return rex_call_func(array('OOAddon', $method), $arguments, false);
    }

    /*protected*/ function baseFolder($addonName)
    {
        return rex_addons_folder($addonName);
    }

    /*protected*/ function mediaFolder($addonName)
    {
        global $REX;
        return $REX['FRONTEND_PATH'] . DIRECTORY_SEPARATOR . $REX['MEDIA_ADDON_DIR'] . DIRECTORY_SEPARATOR . $addonName;
    }
}

/**
 * Manager zum intallieren von OOPlugins
 */
class rex_pluginManager extends rex_baseManager
{
    var $configArray;
    var $addonName;

    // this is the new style constructor used by newer php versions.
    // important: if you change the signatur of this method, change also the signature of rex_pluginManager()
    function __construct($configArray, $addonName)
    {
        $this->rex_pluginManager($configArray, $addonName);
    }

    // this is the deprecated old style constructor kept for compat reasons. 
    function rex_pluginManager($configArray, $addonName)
    {
        $this->configArray = & $configArray;
        $this->addonName = $addonName;
        parent::rex_baseManager('plugin_');
    }

    /**
     * Wandelt ein AddOn in ein PlugIn eines anderen AddOns um
     *
     * @param $addonName AddOn dem das PlugIn eingefügt werden soll
     * @param $pluginName Name des Plugins
     * @param $includeFile Datei die eingebunden und umgewandelt werden soll
     */
    static /*public*/ function addon2plugin($addonName, $pluginName, $includeFile)
    {
        global $REX, $I18N; // Nötig damit im Addon verfügbar

        $ADDONSsic = $REX['ADDON'];
        $REX['ADDON'] = array();

        require $includeFile;

        $plugInConfig = array();
        if (isset($ADDONSsic['plugins'][$addonName])) {
            $plugInConfig = $ADDONSsic['plugins'][$addonName];
        }

        if (isset($REX['ADDON']) && is_array($REX['ADDON'])) {
            foreach (array_keys($REX['ADDON']) as $key) {
                // Alle Eigenschaften die das PlugIn betreffen verschieben
                if (isset($REX['ADDON'][$key][$pluginName])) {
                    $plugInConfig[$key][$pluginName] = $REX['ADDON'][$key][$pluginName];
                    unset($REX['ADDON'][$key][$pluginName]);

                    // ggf array das leer geworden ist löschen
                    // damit es beim merge später nicht ein vorhandes überschreibt
                    if (empty($REX['ADDON'][$key])) {
                        unset($REX['ADDON'][$key]);
                    }
                }
            }
        }

        // Addoneinstellungen als PlugIndaten speichern
        $ADDONSsic['plugins'][$addonName] = $plugInConfig;
        // Alle überbleibenden Keys die ggf. andere Addons beinflussen einfließen lassen
        $REX['ADDON'] = array_merge_recursive($ADDONSsic, $REX['ADDON']);
    }

    /*protected*/ function includeConfig($addonName, $configFile)
    {
        self::addon2plugin($this->addonName, $addonName, $configFile);
    }

    /*protected*/ function includeInstaller($addonName, $installFile)
    {
        self::addon2plugin($this->addonName, $addonName, $installFile);
    }

    /*protected*/ function includeUninstaller($addonName, $uninstallFile)
    {
        self::addon2plugin($this->addonName, $addonName, $uninstallFile);
    }

    /*protected*/ function generateConfig()
    {
        return rex_generatePlugins($this->configArray);
    }

    /*protected*/ function apiCall($method, $arguments)
    {
        if (!is_array($arguments)) {
            trigger_error('Expecting $arguments to be an array!', E_USER_ERROR);
        }

        // addonName als 1. Parameter einfügen
        array_unshift($arguments, $this->addonName);

        return rex_call_func(array('OOPlugin', $method), $arguments, false);
    }

    /*protected*/ function baseFolder($pluginName)
    {
        return rex_plugins_folder($this->addonName, $pluginName);
    }

    /*protected*/ function mediaFolder($pluginName)
    {
        global $REX;
        return $REX['FRONTEND_PATH'] . DIRECTORY_SEPARATOR . $REX['MEDIA_ADDON_DIR'] . DIRECTORY_SEPARATOR . $this->addonName . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . $pluginName;
    }
}
