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

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/price.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once './class/upinvoicefiles.class.php';
require_once './class/upinvoiceinvoice.class.php';

// Control access
if (!$user->rights->facture->lire) accessforbidden();

// Load translations
$langs->loadLangs(array("upinvoice@upinvoice", "bills", "companies", "other"));

// Get file ID
$file_id = GETPOST('file_id', 'int');
if (empty($file_id)) {
    setEventMessages($langs->trans("NoFileSelected"), null, 'errors');
    header("Location: ".dol_buildpath('/upinvoice/upload.php', 1));
    exit;
}

// Initialize objects
$upinvoicefiles = new UpInvoiceFiles($db);
$upinvoiceinvoice = new UpInvoiceInvoice($db);
$form = new Form($db);
$formfile = new FormFile($db);
$formother = new FormOther($db);

// Fetch file record
$result = $upinvoicefiles->fetch($file_id);
if ($result <= 0) {
    setEventMessages($langs->trans("FileNotFound"), null, 'errors');
    header("Location: ".dol_buildpath('/upinvoice/upload.php', 1));
    exit;
}

// Check if file has API JSON data
if (empty($upinvoicefiles->api_json)) {
    setEventMessages($langs->trans("FileNotProcessed"), null, 'errors');
    header("Location: ".dol_buildpath('/upinvoice/upload.php', 1));
    exit;
}

// Parse JSON data
$invoice_data = json_decode($upinvoicefiles->api_json, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    setEventMessages($langs->trans("InvalidJSONData").': '.json_last_error_msg(), null, 'errors');
    header("Location: ".dol_buildpath('/upinvoice/upload.php', 1));
    exit;
}

// Initialize variables
$action = GETPOST('action', 'alpha');
$warnings = array();
$invoice_id = 0;
$validate_invoice = false; // Nueva variable para controlar si se debe validar la factura

// Actions handling
if ($action == 'change_supplier') {
    // Eliminar la referencia al proveedor actual
    if (is_array($invoice_data) && isset($invoice_data['supplier'])) {
        // Mantenemos los datos del proveedor pero eliminamos su ID
        if (isset($invoice_data['supplier']['id'])) {
            unset($invoice_data['supplier']['id']);
        }
        $upinvoicefiles->api_json = json_encode($invoice_data);
    }
    
    // Actualizar el registro del archivo
    $upinvoicefiles->fk_supplier = 0; // Eliminar relación con proveedor
    $upinvoicefiles->import_step = 2; // Volver al paso de selección de proveedor
    $result = $upinvoicefiles->update($user);
    
    if ($result > 0) {
        // Redirigir a la página de selección de proveedor
        header("Location: ".dol_buildpath('/upinvoice/supplier.php', 1)."?file_id=".$upinvoicefiles->id.'&action=change_supplier_from_invoice');
        exit;
    } else {
        setEventMessages($langs->trans("ErrorUpdatingFile"), null, 'errors');
    }
}

// Check if file has supplier ID
if (empty($upinvoicefiles->fk_supplier)) {
    setEventMessages($langs->trans("NoSupplierSelected"), null, 'errors');
    header("Location: ".dol_buildpath('/upinvoice/supplier.php', 1)."?file_id=".$upinvoicefiles->id);
    exit;
}

// Get supplier data
$supplier = new Fournisseur($db);
$supplier->fetch($upinvoicefiles->fk_supplier);
if ($supplier->id <= 0) {
    setEventMessages($langs->trans("SupplierNotFound"), null, 'errors');
    header("Location: ".dol_buildpath('/upinvoice/supplier.php', 1)."?file_id=".$upinvoicefiles->id);
    exit;
}

// Get payment options from recent invoices
$payment_options = $upinvoiceinvoice->getSupplierPaymentOptions($supplier->id);

// Actions handling
if ($action == 'create_invoice') {
    // Comprobar si se ha solicitado la validación
    $validate_invoice = (GETPOST('validate_invoice', 'int') == 1);

    $date = dol_mktime(0, 0, 0, GETPOST('datemonth', 'none'), GETPOST('dateday', 'none'), GETPOST('dateyear', 'none'));
    if(!$date){
        $date = dol_mktime(0, 0, 0, GETPOST('date_month', 'none'), GETPOST('date_day', 'none'), GETPOST('date_year', 'none'));
    }
    // Collect form data
    $form_invoice_data = array(
        'ref_supplier' => GETPOST('ref_supplier', 'alpha'),
        'date' => $date,
        'cond_reglement_id' => GETPOST('cond_reglement_id', 'none'),
        'mode_reglement_id' => GETPOST('mode_reglement_id', 'none'),
        'fk_account' => GETPOST('fk_account', 'int'),
        'note_public' => GETPOST('note_public', 'alpha'),
        'note_private' => GETPOST('note_private', 'alpha'),
        'total_ht' => price2num(GETPOST('total_ht', 'alpha')),
        'total_tva' => price2num(GETPOST('total_tva', 'alpha')),
        'total_ttc' => price2num(GETPOST('total_ttc', 'alpha')),
        'lines' => array(),
        'validate' => $validate_invoice // Pasar el flag de validación a la función de creación
    );
    
    // Process invoice lines
    $line_count = GETPOST('line_count', 'int');
    for ($i = 0; $i < $line_count; $i++) {
        if (empty(GETPOST('line_qty_'.$i, 'alpha')) && empty(GETPOST('line_desc_'.$i, 'alpha'))) {
            $warnings[] = array(
                'message' => 'MissingData',
                'detected' => $langs->trans("Line").' '.($i + 1)
            );
            continue;
        }
        $line = array(
            'product_desc' => GETPOST('line_desc_'.$i, 'alpha'),
            'qty' => price2num(GETPOST('line_qty_'.$i, 'alpha')),
            'pu_ht' => price2num(GETPOST('line_pu_ht_'.$i, 'alpha')),
            'tva_tx' => price2num(GETPOST('line_tva_tx_'.$i, 'alpha')),
            'total_ht' => price2num(GETPOST('line_total_ht_'.$i, 'alpha')),
            'total_tva' => price2num(GETPOST('line_total_tva_'.$i, 'alpha')),
            'total_ttc' => price2num(GETPOST('line_total_ttc_'.$i, 'alpha')),
            'product_type' => GETPOST('line_type_'.$i, 'none')
        );
        $form_invoice_data['lines'][] = $line;
    }
    
    // Create invoice
    $result = $upinvoiceinvoice->createInvoice(
        $form_invoice_data,
        $supplier->id,
        $user,
        $upinvoicefiles->file_path
    );
    
    if ($result['status'] == 'success') {
        // Update file record with invoice ID
        $upinvoicefiles->fk_invoice = $result['id'];
        $upinvoicefiles->status = 1; // Processed
        $upinvoicefiles->import_step = 4; // Completed
        // quitamos errores de import_error
        $upinvoicefiles->import_error = '';
        $update_result = $upinvoicefiles->update($user);
        
        if ($update_result > 0) {
            setEventMessages($result['message'], null);
            // Redirect to invoice list or step 1 to process another file
            header("Location: ".dol_buildpath('/upinvoice/upload.php', 1)."?invoice_created=1");
            exit;
        } else {
            setEventMessages($langs->trans("ErrorUpdatingFile"), null, 'errors');
        }
    } else {
        setEventMessages($result['message'], null, 'errors');
    }
}

// Define page title
$page_name = "InvoiceValidation";
$help_url = '';
$morejs = array(
    '/upinvoice/js/upinvoiceimport.js'
);
$morecss = array('/upinvoice/css/upinvoiceimport.css');

// Page header
llxHeader('', $langs->trans($page_name), $help_url, '', 0, 0, $morejs, $morecss);

print load_fiche_titre($langs->trans($page_name), '', 'title_accountancy');

// Display current file and supplier info
print '<div class="upinvoiceimport-container">';

// File info card
print '<table class="noborder centpercent">';
print '<tr class="oddeven">';
print '<td width="50%">';
//print '<strong>' . $langs->trans("FileName") . ':</strong> ' . dol_escape_htmltag($upinvoicefiles->original_filename) . '<div style="float:right"><button id="preview-doc-btn" class="butAction"><i class="fas fa-eye"></i> ' . $langs->trans("ViewDocument") . '</button></div><br>';
$previewUrl = dol_buildpath('/viewimage.php', 1).'?modulepart=upinvoice&file=temp/'.urlencode(basename($upinvoicefiles->file_path)).'&cache=0';
print '<strong>' . $langs->trans("FileName") . ':</strong> ' . dol_escape_htmltag($upinvoicefiles->original_filename);
print '<div style="float:right">';
print '<button id="preview-doc-btn" class="butAction" ';
print 'data-file-path="'.$previewUrl.'" ';
print 'data-file-type="'.$upinvoicefiles->file_type.'" ';
print 'data-file-name="'.$upinvoicefiles->original_filename.'">';
print '<i class="fas fa-eye"></i> ' . $langs->trans("ViewDocument") . '</button>';
print '</div><br>';
print '<strong>' . $langs->trans("UploadDate") . ':</strong> ' . dol_print_date($upinvoicefiles->date_creation, 'dayhour');
print '</td>';
print '<td width="50%">';
print '<strong>' . $langs->trans("Name") . ':</strong> ' . $supplier->name;

// Botón para cambiar de proveedor
print ' <a href="' . $_SERVER['PHP_SELF'] . '?action=change_supplier&file_id=' . $upinvoicefiles->id . '" class="butAction butActionDelete butActionSmall">';
print '<i class="fas fa-exchange-alt"></i> ' . $langs->trans("ChangeSupplier") . '</a><br>';

if (!empty($supplier->idprof1)) print '<strong>' . $langs->trans("ProfId1") . ':</strong> ' . $supplier->idprof1 . '<br>';
if (!empty($supplier->tva_intra)) print '<strong>' . $langs->trans("VATIntra") . ':</strong> ' . $supplier->tva_intra . '<br>';
print '<strong>' . $langs->trans("Address") . ':</strong> ' . $supplier->address . ', ' . $supplier->zip . ' ' . $supplier->town;
print '</td>';
print '</tr>';
print '</table>';

// Datos de documento para vista previa
$documentPreviewUrl = dol_buildpath('/viewimage.php', 1).'?modulepart=upinvoice&file=temp/'.urlencode(basename($upinvoicefiles->file_path)).'&cache=0';
print '<input type="hidden" id="document-preview-path" value="'.$documentPreviewUrl.'">';
print '<input type="hidden" id="document-preview-type" value="'.$upinvoicefiles->file_type.'">';

// Start invoice form
print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '" name="invoice_form" id="invoice_form">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="create_invoice">';
print '<input type="hidden" name="file_id" value="' . $upinvoicefiles->id . '">';

// Invoice data card
print '<div class="fichecenter">';
print '<div class="fichehalfleft">';

// Display warnings if any
if (!empty($warnings)) {
    print '<div class="upinvoiceimport-warnings">';
    print '<h3>' . $langs->trans("WarningsDetected") . '</h3>';
    
    foreach ($warnings as $warning) {
        print '<div class="upinvoiceimport-warning">';
        print '<i class="fas fa-exclamation-triangle"></i> ';
        print $langs->trans($warning['message']) . ': ';
        print $langs->trans("Detected") . ': <strong>' . $warning['detected'] . '</strong>, ';
        print $langs->trans("Entered") . ': <strong>' . $warning['entered'] . '</strong>';
        print '</div>';
    }
    
    print '</div>';
}

// Invoice header
print '<table class="border centpercent">';

// Invoice reference
print '<tr>';
print '<td class="titlefieldcreate fieldrequired">' . $langs->trans("RefSupplier") . '</td>';
print '<td>';
print '<input type="text" name="ref_supplier" value="' . dol_escape_htmltag($invoice_data['ref_supplier'] ?? '') . '" required>';
print '</td>';
print '</tr>';

// Invoice date
print '<tr>';
print '<td class="fieldrequired">' . $langs->trans("InvoiceDate") . '</td>';
print '<td>';
$invoiceDate = !empty($invoice_data['date']) ? dol_stringtotime($invoice_data['date']) : dol_now();
print $form->selectDate($invoiceDate, 'date', '', '', '', "invoice_form", 1, 1);
print '</td>';
print '</tr>';

// Payment terms
print '<tr>';
print '<td>' . $langs->trans("PaymentConditionsShort") . '</td>';
print '<td>';
// Use proper payment terms selection function with preselection based on most common value
$form->select_conditions_paiements($payment_options['cond_reglement_id'], 'cond_reglement_id', -1, 1);
print '</td>';
print '</tr>';

// Payment mode
print '<tr>';
print '<td>' . $langs->trans("PaymentMode") . '</td>';
print '<td>';
// Use proper payment mode selection function with preselection based on most common value
$form->select_types_paiements($payment_options['mode_reglement_id'], 'mode_reglement_id', '', 0, 1, 0, 0, 1);
print '</td>';
print '</tr>';

// Bank account
print '<tr>';
print '<td>' . $langs->trans("BankAccount") . '</td>';
print '<td>';
// Use proper bank account selection function with preselection based on most common value
$form->select_comptes($payment_options['fk_account'], 'fk_account', 0, '', 1);
print '</td>';
print '</tr>';

// Notes
print '<tr>';
print '<td>' . $langs->trans("Notes") . '</td>';
print '<td>';
print '<textarea name="note_public" rows="3" class="flat quatrevingtpercent" placeholder="' . $langs->trans("PublicNotes") . '"></textarea>';
print '<br>';
print '<textarea name="note_private" rows="3" class="flat quatrevingtpercent" placeholder="' . $langs->trans("PrivateNotes") . '"></textarea>';
print '</td>';
print '</tr>';

print '</table>';

print '</div>'; // Close fichehalfleft


print '<div class="fichehalfright">';

// Invoice totals
print '<table class="border centpercent">';

// Total HT
print '<tr class="liste_titre">';
print '<td class="titlefieldcreate">' . $langs->trans("TotalHT") . '</td>';
print '<td class="right">';
print '<input type="text" name="total_ht" id="total_ht" class="flat right" value="'.price($invoice_data['total_ht'] ?? 0).'" readonly>';
print '</td>';
print '</tr>';

// Total VAT
print '<tr>';
print '<td>' . $langs->trans("TotalVAT") . '</td>';
print '<td class="right">';
print '<input type="text" name="total_tva" id="total_tva" class="flat right" value="'.price($invoice_data['total_tva'] ?? 0).'">';
print '</td>';
print '</tr>';

// Total TTC
print '<tr class="liste_titre">';
print '<td>' . $langs->trans("TotalTTC") . '</td>';
print '<td class="right">';
print '<input type="text" name="total_ttc" id="total_ttc" class="flat right" value="'.price($invoice_data['total_ttc'] ?? 0).'">';
print '</td>';
print '</tr>';

print '</table>';

print '</div>'; // Close fichehalfright

print '</div>'; // Close fichecenter

print '<div class="clearboth"></div><br>';

// Invoice lines card
print '<div class="upinvoiceimport-invoice-card">';
print '<div class="upinvoiceimport-card-body">';

// Invoice lines table
print '<table class="noborder centpercent" id="invoice_lines_table">';
print '<tr class="liste_titre">';
print '<th class="linecoldescription minwidth300imp">' . $langs->trans("Description") . '</th>';
print '<th class="center">' . $langs->trans("VAT") . '</th>';
print '<th class="center">' . $langs->trans("Qty") . '</th>';
print '<th class="center">' . $langs->trans("PriceUHT") . '</th>';
print '<th class="right">' . $langs->trans("TotalHT") . '</th>';
print '<th class="right">' . $langs->trans("TotalVAT") . '</th>';
print '<th class="right">' . $langs->trans("TotalTTC") . '</th>';
print '<th class="center">' . $langs->trans("Actions") . '</th>';
print '</tr>';

// Lines from JSON data
$line_count = 0;
if (!empty($invoice_data['lines']) && is_array($invoice_data['lines'])) {
    foreach ($invoice_data['lines'] as $line) {
        print '<tr class="invoice-line" id="line_row_'.$line_count.'">';
        
        // Description
        print '<td>';
        print '<textarea name="line_desc_'.$line_count.'" class="flat width100p" style="margin-top: 5px; width: 98%">'.dol_escape_htmltag($line['product_desc']).'</textarea>';
        print '<input type="hidden" name="line_type_'.$line_count.'" value="'.(isset($line['product_type']) ? $line['product_type'] : '0').'">';
        print '</td>';
        
        // VAT rate
        print '<td class="center">';
        print '<input type="text" size="5" name="line_tva_tx_'.$line_count.'" value="'.price(isset($line['tva_tx']) ? $line['tva_tx'] : 0).'" class="flat tvaline right" onchange="updateLineTotals('.$line_count.')">';
        print '</td>';
        
        // Quantity
        print '<td class="center">';
        print '<input type="text" size="5" name="line_qty_'.$line_count.'" value="'.price(isset($line['qty']) ? $line['qty'] : 1).'" class="flat qtyline right" onchange="updateLineTotals('.$line_count.')">';
        print '</td>';
        
        // Unit price
        print '<td class="center">';
        print '<input type="text" size="8" name="line_pu_ht_'.$line_count.'" value="'.price(isset($line['pu_ht']) ? $line['pu_ht'] : 0).'" class="flat puhline right" onchange="updateLineTotals('.$line_count.')">';
        print '</td>';
        
        // Total HT
        print '<td class="right">';
        print '<input type="text" size="8" name="line_total_ht_'.$line_count.'" value="'.price(isset($line['total_ht']) ? $line['total_ht'] : 0).'" class="flat totalhtline right" readonly>';
        print '</td>';
        
        // Total VAT
        print '<td class="right">';
        print '<input type="text" size="8" name="line_total_tva_'.$line_count.'" value="'.price(isset($line['total_tva']) ? $line['total_tva'] : 0).'" class="flat totalvaline right">';
        print '</td>';
        
        // Total TTC
        print '<td class="right">';
        print '<input type="text" size="8" name="line_total_ttc_'.$line_count.'" value="'.price(isset($line['total_ttc']) ? $line['total_ttc'] : 0).'" class="flat totalttcline right">';
        print '</td>';
        
        // Actions
        print '<td class="center">';
        print '<a href="#" class="delete-line" data-line="'.$line_count.'"><i class="fas fa-trash"></i></a>';
        print '</td>';
        
        print '</tr>';
        $line_count++;
    }
}

// Add an empty line at the end
/* print '<tr class="invoice-line" id="line_row_'.$line_count.'">';
print '<td><input type="text" name="line_desc_'.$line_count.'" class="flat width100p"><input type="hidden" name="line_type_'.$line_count.'" value="0"></td>';
print '<td class="center"><input type="text" size="5" name="line_tva_tx_'.$line_count.'" value="'.(!empty($invoice_data['tva_tx']) ? price($invoice_data['tva_tx']) : price(0)).'" class="flat tvaline right" onchange="updateLineTotals('.$line_count.')"></td>';
print '<td class="center"><input type="text" size="5" name="line_qty_'.$line_count.'" value="1" class="flat qtyline right" onchange="updateLineTotals('.$line_count.')"></td>';
print '<td class="center"><input type="text" size="8" name="line_pu_ht_'.$line_count.'" value="0" class="flat puhline right" onchange="updateLineTotals('.$line_count.')"></td>';
print '<td class="right"><input type="text" size="8" name="line_total_ht_'.$line_count.'" value="0" class="flat totalhtline right" readonly></td>';
print '<td class="right"><input type="text" size="8" name="line_total_tva_'.$line_count.'" value="0" class="flat totalvaline right" readonly></td>';
print '<td class="right"><input type="text" size="8" name="line_total_ttc_'.$line_count.'" value="0" class="flat totalttcline right" readonly></td>';
print '<td class="center"><a href="#" class="delete-line" data-line="'.$line_count.'"><i class="fas fa-trash"></i></a></td>';
print '</tr>';
$line_count++; */

print '</table>';

// Add line button
print '<div class="invoice-actions">';
print '<a href="#" class="butAction" id="add-line-btn">' . $langs->trans("AddLine") . '</a>';
print '</div>';

// Input to keep track of line count
print '<input type="hidden" name="line_count" id="line_count" value="'.$line_count.'">';

print '</div>'; // Close card body
print '</div>'; // Close card


// Submit buttons - Modificado para diferenciar los botones
print '<div class="center">';
print '<input type="submit" class="button" name="create_only" value="' . $langs->trans("CreateInvoice") . '">';
print ' &nbsp; ';
print '<input type="submit" class="button" name="create_validate" value="' . $langs->trans("CreateAndValidateInvoice") . '" onclick="document.getElementById(\'validate_invoice\').value=\'1\';">';
print ' &nbsp; ';
print '<a href="'.dol_buildpath('/upinvoice/upload.php',1).'" class="button">' . $langs->trans("Cancel") . '</a>';
print '</div>';

// Campo oculto para indicar si validar o no
print '<input type="hidden" id="validate_invoice" name="validate_invoice" value="0">';

print '</form>';

// Close container
print '</div>';

// Add JavaScript for managing invoice lines and calculations
?>
<script type="text/javascript">
    var upinvoiceimport_root = '<?php echo dirname($_SERVER['PHP_SELF']); ?>';
    var upinvoiceimport_token = '<?php echo newToken(); ?>';
    var upinvoiceimport_langs = {
        'Searching': '<?php echo $langs->trans("Searching"); ?>',
        'FoundSuppliers': '<?php echo $langs->trans("FoundSuppliers"); ?>',
        'Name': '<?php echo $langs->trans("Name"); ?>',
        'TaxIDs': '<?php echo $langs->trans("TaxIDs"); ?>',
        'Address': '<?php echo $langs->trans("Address"); ?>',
        'Actions': '<?php echo $langs->trans("Actions"); ?>',
        'SelectThisSupplier': '<?php echo $langs->trans("SelectThisSupplier"); ?>',
        'NoSuppliersFound': '<?php echo $langs->trans("NoSuppliersFound"); ?>',
        'ErrorProcessingResponse': '<?php echo $langs->trans("ErrorProcessingResponse"); ?>',
        'SearchFailed': '<?php echo $langs->trans("SearchFailed"); ?>',
        'ErrorConfirmingSupplier': '<?php echo $langs->trans("ErrorConfirmingSupplier"); ?>'
    };
</script>

<script type="text/javascript">
$(document).ready(function() {    
    // Add line button
    $("#add-line-btn").click(function(e) {
        e.preventDefault();
        addNewLine();
    });
    
    // Delete line button
    $(document).on('click', '.delete-line', function(e) {
        e.preventDefault();
        var lineNum = $(this).data('line');
        deleteLine(lineNum);
    });
    
    // Initialize line totals
    for (var i = 0; i < <?php echo $line_count; ?>; i++) {
        updateLineTotals(i);
    }
    
    // Calculate invoice totals
    updateInvoiceTotals();
    
    // Gestionar el botón de "Crear factura" (sin validar)
    $("input[name='create_only']").click(function() {
        // Asegurarse de que el campo validate_invoice está a 0
        document.getElementById('validate_invoice').value = '0';
    });
});

// Update line totals
function updateLineTotals(lineNum) {
    var qty = parseFloat($("input[name='line_qty_" + lineNum + "']").val().replace(/,/g, '.')) || 0;
    var pu_ht = parseFloat($("input[name='line_pu_ht_" + lineNum + "']").val().replace(/,/g, '.')) || 0;
    var tva_tx = parseFloat($("input[name='line_tva_tx_" + lineNum + "']").val().replace(/,/g, '.')) || 0;
    
    var total_ht = qty * pu_ht;
    var total_tva = total_ht * (tva_tx / 100);
    var total_ttc = total_ht + total_tva;
    
    $("input[name='line_total_ht_" + lineNum + "']").val(total_ht.toFixed(2));
    $("input[name='line_total_tva_" + lineNum + "']").val(total_tva.toFixed(2));
    $("input[name='line_total_ttc_" + lineNum + "']").val(total_ttc.toFixed(2));
    
    updateInvoiceTotals();
}

// Update invoice totals
function updateInvoiceTotals() {
    var total_ht = 0;
    var total_tva = 0;
    var total_ttc = 0;
    
    $(".totalhtline").each(function() {
        total_ht += parseFloat($(this).val()) || 0;
    });
    
    $(".totalvaline").each(function() {
        total_tva += parseFloat($(this).val()) || 0;
    });
    
    $(".totalttcline").each(function() {
        total_ttc += parseFloat($(this).val()) || 0;
    });
    
    $("#total_ht").val(total_ht.toFixed(2));
    $("#total_tva").val(total_tva.toFixed(2));
    $("#total_ttc").val(total_ttc.toFixed(2));
}

// Add a new line
function addNewLine() {
    var lineCount = parseInt($("#line_count").val());
    var tableBody = $("#invoice_lines_table");
    var defaultVat = $("input[name='line_tva_tx_0']").val() || "0";
    
    var newLine = '<tr class="invoice-line" id="line_row_' + lineCount + '">' +
        '<td><textarea name="line_desc_' + lineCount + '" class="flat width100p" style="margin-top: 5px; width: 98%"></textarea><input type="hidden" name="line_type_' + lineCount + '" value="0"></td>' +
        '<td class="center"><input type="text" size="5" name="line_tva_tx_' + lineCount + '" value="' + defaultVat + '" class="flat tvaline right" onchange="updateLineTotals(' + lineCount + ')"></td>' +
        '<td class="center"><input type="text" size="5" name="line_qty_' + lineCount + '" value="1" class="flat qtyline right" onchange="updateLineTotals(' + lineCount + ')"></td>' +
        '<td class="center"><input type="text" size="8" name="line_pu_ht_' + lineCount + '" value="0" class="flat puhline right" onchange="updateLineTotals(' + lineCount + ')"></td>' +
        '<td class="right"><input type="text" size="8" name="line_total_ht_' + lineCount + '" value="0" class="flat totalhtline right" readonly></td>' +
        '<td class="right"><input type="text" size="8" name="line_total_tva_' + lineCount + '" value="0" class="flat totalvaline right" readonly></td>' +
        '<td class="right"><input type="text" size="8" name="line_total_ttc_' + lineCount + '" value="0" class="flat totalttcline right" readonly></td>' +
        '<td class="center"><a href="#" class="delete-line" data-line="' + lineCount + '"><i class="fas fa-trash"></i></a></td>' +
        '</tr>';
    
    tableBody.append(newLine);
    $("#line_count").val(lineCount + 1);
    updateLineTotals(lineCount);
}

// Delete a line
function deleteLine(lineNum) {
    if ($('.invoice-line').length <= 1) {
        alert('<?php echo $langs->trans("CannotDeleteLastLine"); ?>');
        return;
    }
    
    $('#line_row_' + lineNum).remove();
    updateInvoiceTotals();
}
</script>
<?php
// Footer
llxFooter();
$db->close();