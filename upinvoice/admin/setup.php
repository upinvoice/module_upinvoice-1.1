<?php
/* Copyright (C) 2023
 * Licensed under the GNU General Public License version 3
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
    $i--;
    $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
    $res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
    $res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
    $res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}

global $langs, $user;

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formadmin.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';

// Load translation files
$langs->loadLangs(array("admin", "upinvoice@upinvoice"));

// Access control
if (!$user->admin) accessforbidden();

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

$value = GETPOST('value', 'alpha');
$label = GETPOST('label', 'alpha');

$error = 0;
$setupNotice = '';

// Initialize technical objects
$form = new Form($db);
$formadmin = new FormAdmin($db);
$formother = new FormOther($db);

// Actions
if ($action == 'update') {
    //$res = dolibarr_set_const($db, "UPINVOICE_API_URL", GETPOST("UPINVOICE_API_URL", 'alpha'), 'chaine', 0, '', $conf->entity);
    //if (!($res > 0)) $error++;
    
    $res = dolibarr_set_const($db, "UPINVOICE_API_KEY", GETPOST("UPINVOICE_API_KEY", 'alpha'), 'chaine', 0, '', $conf->entity);
    if (!($res > 0)) $error++;
    
    if (!$error) {
        setEventMessages($langs->trans("SetupSaved"), null);
    } else {
        setEventMessages($langs->trans("Error"), null, 'errors');
    }
    
    $action = '';
}

/*
 * View
 */
$page_name = "UpInvoiceSetup";
$help_url = '';

llxHeader('', $langs->trans($page_name), $help_url);

// Subheader
$linkback = '<a href="'.($backtopage ?: DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = upinvoiceimport_admin_prepare_head();
print dol_get_fiche_head($head, 'settings', $langs->trans("Module500000Name"), -1, "bill");

// Module description
print '<div class="upinvoiceimport-description">';
print '<p>' . $langs->trans("UpInvoiceImportDescription") . '</p>';
print '</div>';

if ($setupNotice) print info_admin($setupNotice);

// Formulario de configuración
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td class="titlefield">' . $langs->trans("Parameter") . '</td>';
print '<td>' . $langs->trans("Value") . '</td>';
print '<td>' . $langs->trans("Comment") . '</td>';
print '</tr>';

// API URL
print '<tr class="oddeven">';
print '<td>' . $langs->trans("APIUrl") . '</td>';
print '<td>';
print '<input type="text" name="UPINVOICE_API_URL" value="https://upinvoice.eu/api/process-invoice" size="50" class="flat" disabled>';
print '</td>';
print '<td>' . $langs->trans("APIUrlHelp") . '</td>';
print '</tr>';

// API Key
print '<tr class="oddeven">';
print '<td>' . $langs->trans("APIKey") . '</td>';
print '<td>';
print '<input type="text" name="UPINVOICE_API_KEY" value="' . $conf->global->UPINVOICE_API_KEY . '" size="50" class="flat">';
print '</td>';
print '<td>' . $langs->trans("APIKeyHelp") . '</td>';
print '</tr>';

// Cómo conseguir una clave API (genera una clave autenticándote en upinvoice.eu y accediendo a https://upinvoice.eu/api/tokens con link)
print '<tr class="oddeven">';
print '<td colspan="3">';
print '<p>' . $langs->trans("HowToGetAPIKey") . '</p>';
print '</td>';
print '</tr>';

print '</table>';

print '<div class="tabsAction">';
print '<input type="submit" class="button" value="' . $langs->trans("Save") . '">';
print '</div>';

print '</form>';

// Test API Connection button
/*print '<br>';
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="test">';
print '<div class="center">';
print '<input type="submit" class="button" value="' . $langs->trans("TestAPIConnection") . '">';
print '</div>';
print '</form>';*/

print dol_get_fiche_end();

// Page end
llxFooter();
$db->close();

/**
 * Prepare admin pages header
 *
 * @return array
 */
function upinvoiceimport_admin_prepare_head()
{
    global $langs, $conf;
    
    $langs->load("upinvoice@upinvoice");
    
    $h = 0;
    $head = array();
    
    $head[$h][0] = dol_buildpath("/upinvoice/admin/setup.php", 1);
    $head[$h][1] = $langs->trans("Settings");
    $head[$h][2] = 'settings';
    $h++;
    
    // Show more tabs from modules
    // Entries must be declared in modules descriptor with line
    // $this->tabs = array('entity:+tabname:Title:@mymodule:/mymodule/mypage.php?id=__ID__');   to add new tab
    // $this->tabs = array('entity:-tabname:Title:@mymodule:/mymodule/mypage.php?id=__ID__');   to remove a tab
    
    return $head;
}