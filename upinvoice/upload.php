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

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once './class/upinvoicefiles.class.php';

// Control access
if (!$user->rights->facture->lire) accessforbidden();

// Load translations
$langs->loadLangs(array("upinvoice@upinvoice", "bills", "other"));

// Define temp directory
$upload_dir = DOL_DATA_ROOT . '/upinvoice/temp';

// Create temp directory if it doesn't exist
if (!dol_is_dir($upload_dir)) {
    dol_mkdir($upload_dir);
}

// Initialize objects
$form = new Form($db);
$formfile = new FormFile($db);
$upinvoicefiles = new UpInvoiceFiles($db);

// Define page title and other vars
$page_name = "FileUploadTitle";
$help_url = '';
$morejs = array(
    '/upinvoice/js/upinvoiceimport.js'
);
$morecss = array(
    '/upinvoice/css/upinvoiceimport.css'
);

// Get active tab
$active_tab = GETPOST('tab', 'alpha') ? GETPOST('tab', 'alpha') : 'pending';

// Header
llxHeader('', $langs->trans($page_name), $help_url, '', 0, 0, $morejs, $morecss);

print load_fiche_titre($langs->trans($page_name), '', 'title_setup');

// Check if UpInvoice API key is configured
$apiKey = $conf->global->UPINVOICE_API_KEY;
if (empty($apiKey)) {
    print '<div class="warning">';
    print $langs->trans("WarningUpInvoiceAPIKeyNotConfigured");
    if ($user->admin) {
        print ' <a href="' . DOL_URL_ROOT . '/admin/modules.php?search_name=upinvoiceimport">' . $langs->trans("GoToModuleSetup") . '</a>';
    }
    print '</div>';
}

// Start container
print '<div class="upinvoiceimport-container">';

// AJAX response container for upload results
print '<div id="upload-results" class="upinvoiceimport-messages"></div>';

// Eliminado: Botones de procesamiento masivo
// print '<div class="upinvoiceimport-action-buttons">';
// print '<button class="btn btn-primary" id="process-all-files-btn"><i class="fas fa-cogs"></i> ' . $langs->trans('ProcessAllFiles') . '</button>';
// print '<button class="btn btn-secondary" id="toggle-queue-btn"><i class="fas fa-pause"></i> ' . $langs->trans('PauseProcessing') . '</button>';
// print '</div>';

// Drag and drop upload zone with file previews
print '<div class="upinvoiceimport-dropzone-container">';
print '<div class="upinvoiceimport-dropzone" id="dropzone">';
print '<div class="dropzone-content">';
print '<i class="fas fa-upload fa-4x"></i>';
print '<h3>' . $langs->trans('DragDropFiles') . '</h3>';
print '<p>' . $langs->trans('OrClickToSelect') . '</p>';
print '<input type="file" id="fileupload" name="fileupload[]" multiple style="display:none;">';
print '<button class="btn btn-primary" id="select-files">' . $langs->trans('SelectFiles') . '</button>';
print '</div>';
print '</div>';

// Preview zone for files being uploaded
print '<div id="upload-previews" class="upload-previews-container">';
print '</div>';
print '</div>';

// Tabs navigation
print '<div class="tabs" data-role="tabs">';
print '<ul class="tab-nav">';
print '<li class="tab-element' . ($active_tab == 'pending' ? ' active' : '') . '">';
print '<a href="'.dol_buildpath('/upinvoice/upload.php', 1).'?tab=pending" class="tab-link" data-target="pending-files">' . $langs->trans('PendingFiles') . '</a>';
print '</li>';
print '<li class="tab-element' . ($active_tab == 'finished' ? ' active' : '') . '">';
print '<a href="'.dol_buildpath('/upinvoice/upload.php', 1).'?tab=finished" class="tab-link" data-target="finished-files">' . $langs->trans('FinishedFiles') . '</a>';
print '</li>';
print '</ul>';

// Tab content
print '<div class="tab-content-container">';

// Pending Files tab
print '<div id="pending-files" class="tab-content' . ($active_tab == 'pending' ? ' active' : '') . '">';
print '<div class="upinvoiceimport-files-container">';
print '<h3>' . $langs->trans('PendingProcessing') . '</h3>';
print '<div id="pending-files-list" class="upinvoiceimport-files-list"></div>';
print '</div>';
print '</div>'; // End pending files tab

// Finished Files tab
print '<div id="finished-files" class="tab-content' . ($active_tab == 'finished' ? ' active' : '') . '">';
print '<div class="upinvoiceimport-files-container">';
print '<h3>' . $langs->trans('ProcessedInvoices') . '</h3>';

// Tabla de Dolibarr para archivos finalizados - Estructura modificada según los requisitos
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>' . $langs->trans("FileName") . '</th>';
print '<th class="right">' . $langs->trans("Size") . '</th>';
print '<th class="center">' . $langs->trans("UploadDate") . '</th>';
print '<th class="center">' . $langs->trans("CompletionDate") . '</th>';
print '<th class="center">' . $langs->trans("InvoiceDate") . '</th>';
print '<th class="right">' . $langs->trans("TotalTTC") . '</th>';
print '<th>' . $langs->trans("Supplier") . '</th>';
print '<th class="center">' . $langs->trans("Actions") . '</th>';
print '</tr>';

// El contenido de la tabla será cargado dinámicamente por AJAX
print '<tbody id="finished-files-list"></tbody>';
print '</table>';

print '</div>';
print '</div>'; // End finished files tab

print '</div>'; // End tab content

print '</div>'; // End tabs

print '</div>'; // Close container

// Add JavaScript for the page
?>
<script type="text/javascript">
    var upinvoiceimport_root = '<?php echo dirname($_SERVER['PHP_SELF']); ?>';
    var upinvoiceimport_token = '<?php echo newToken(); ?>';
    var upinvoiceimport_active_tab = '<?php echo $active_tab; ?>';
    var upinvoiceimport_langs = {
        'ConfirmDeleteFile': '<?php echo html_entity_decode($langs->trans("ConfirmDeleteFile")); ?>',
        'ErrorProcessingResponse': '<?php echo html_entity_decode($langs->trans("ErrorProcessingResponse")); ?>',
        'DeleteFailed': '<?php echo html_entity_decode($langs->trans("DeleteFailed")); ?>',
        'Processing': '<?php echo html_entity_decode($langs->trans("Processing")); ?>',
        'Processed': '<?php echo html_entity_decode($langs->trans("Processed")); ?>',
        'FileProcessedSuccessfully': '<?php echo html_entity_decode($langs->trans("FileProcessedSuccessfully")); ?>',
        'ProcessingFailed': '<?php echo html_entity_decode($langs->trans("ProcessingFailed")); ?>',
        'NextStep': '<?php echo html_entity_decode($langs->trans("NextStep")); ?>',
        'Retry': '<?php echo html_entity_decode($langs->trans("Retry")); ?>',
        'PreviewNotAvailable': '<?php echo html_entity_decode($langs->trans("PreviewNotAvailable")); ?>',
        'ProcessingInProgress': '<?php echo html_entity_decode($langs->trans("ProcessingInProgress")); ?>',
        'FilePreview': '<?php echo html_entity_decode($langs->trans("FilePreview")); ?>',
        'ValidateInvoice': '<?php echo html_entity_decode($langs->trans("ValidateInvoice")); ?>',
        'ViewInvoice': '<?php echo html_entity_decode($langs->trans("ViewInvoice")); ?>',
        'Loading': '<?php echo html_entity_decode($langs->trans("Loading")); ?>',
        'NoPendingFiles': '<?php echo html_entity_decode($langs->trans("NoPendingFiles")); ?>',
        'NoFinishedFiles': '<?php echo html_entity_decode($langs->trans("NoFinishedFiles")); ?>'
    };
</script>
<script type="text/javascript">
$(document).ready(function() {
    // Initialize the uploader
    var dropzone = document.getElementById('dropzone');
    var fileInput = document.getElementById('fileupload');
    var selectButton = document.getElementById('select-files');
    var uploadPreviews = document.getElementById('upload-previews');
    
    // Handle file selection button
    selectButton.addEventListener('click', function(e) {
        e.preventDefault();
        fileInput.click();
    });
    
    // Handle file input change
    fileInput.addEventListener('change', function() {
        if (this.files.length) {
            handleFiles(this.files);
        }
    });
    
    // Handle drag and drop events
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropzone.addEventListener(eventName, preventDefaults, false);
    });
    
    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }
    
    ['dragenter', 'dragover'].forEach(eventName => {
        dropzone.addEventListener(eventName, highlight, false);
    });
    
    ['dragleave', 'drop'].forEach(eventName => {
        dropzone.addEventListener(eventName, unhighlight, false);
    });
    
    function highlight() {
        dropzone.classList.add('highlight');
    }
    
    function unhighlight() {
        dropzone.classList.remove('highlight');
    }
    
    // Handle dropped files
    dropzone.addEventListener('drop', function(e) {
        var dt = e.dataTransfer;
        var files = dt.files;
        
        handleFiles(files);
    });
    
    // Tabs functionality
    $('.tab-link').on('click', function(e) {
        e.preventDefault();
        var targetTab = $(this).data('target');
        
        // Update URL with tab parameter without reloading
        var url = new URL(window.location.href);
        url.searchParams.set('tab', targetTab === 'pending-files' ? 'pending' : 'finished');
        window.history.pushState({}, '', url);
        
        // Activate tab
        $('.tab-element').removeClass('active');
        $(this).parent().addClass('active');
        
        // Show tab content
        $('.tab-content').removeClass('active');
        $('#' + targetTab).addClass('active');
        
        // Update active tab variable
        upinvoiceimport_active_tab = targetTab === 'pending-files' ? 'pending' : 'finished';
        
        // Reload the appropriate file list
        loadFilesList();
    });
    
    // Process files
    function handleFiles(files) {
        if (files.length === 0) return;
        
        // First show file previews
        showFilePreviews(files);
        
        // Check for duplicates
        checkForDuplicates(files, function(nonDuplicateFiles) {
            if (nonDuplicateFiles.length === 0) {
                // All files were duplicates
                showNotification('<i class="fas fa-info-circle"></i> ' + '<?php echo $langs->trans('AllFilesAreDuplicates'); ?>', 'warning');
                return;
            }
            
            uploadFiles(nonDuplicateFiles);
        });
    }
    
    // Show file previews before upload
    function showFilePreviews(files) {
        uploadPreviews.innerHTML = '';
        
        for (var i = 0; i < files.length; i++) {
            var file = files[i];
            var previewItem = document.createElement('div');
            previewItem.className = 'upload-preview-item';
            
            // Add appropriate icon based on file type
            var iconClass = 'fas fa-file';
            if (file.type.indexOf('pdf') !== -1) {
                iconClass = 'fas fa-file-pdf';
            } else if (file.type.indexOf('image') !== -1) {
                iconClass = 'fas fa-file-image';
            }
            
            // Create preview thumbnail
            var thumb = document.createElement('div');
            thumb.className = 'preview-thumbnail';
            
            // For images, show actual thumbnail
            if (file.type.indexOf('image') !== -1) {
                var img = document.createElement('img');
                img.file = file;
                thumb.appendChild(img);
                
                // Use FileReader to get image data
                var reader = new FileReader();
                reader.onload = (function(aImg) { 
                    return function(e) { 
                        aImg.src = e.target.result; 
                    }; 
                })(img);
                reader.readAsDataURL(file);
            } else {
                // For non-images, show icon
                var icon = document.createElement('i');
                icon.className = iconClass;
                thumb.appendChild(icon);
            }
            
            // Add file info
            var info = document.createElement('div');
            info.className = 'preview-info';
            info.innerHTML = '<div class="preview-name">' + file.name + '</div>' +
                            '<div class="preview-size">' + formatFileSize(file.size) + '</div>';
            
            previewItem.appendChild(thumb);
            previewItem.appendChild(info);
            
            // Add progress bar
            var progressBar = document.createElement('div');
            progressBar.className = 'preview-progress';
            progressBar.innerHTML = createProgressBar(0);
            previewItem.appendChild(progressBar);
            
            uploadPreviews.appendChild(previewItem);
        }
        
        // Show upload previews container
        uploadPreviews.style.display = 'flex';
    }
    
    // Update preview progress
    function updatePreviewProgress(index, percentage) {
        var previewItems = uploadPreviews.querySelectorAll('.upload-preview-item');
        if (index < previewItems.length) {
            var progressBar = previewItems[index].querySelector('.preview-progress');
            updateProgressBar(progressBar, percentage);
        }
    }
    
    // Check for duplicate files - this checks both pending and finished files
    function checkForDuplicates(files, callback) {
        var nonDuplicateFiles = [];
        var checkCount = 0;
        var duplicateCount = 0;
        
        for (var i = 0; i < files.length; i++) {
            (function(file, index) {
                // Check if this file already exists
                $.ajax({
                    url: '<?php echo dol_buildpath('/upinvoice/ajax/check_duplicate.php', 1); ?>',
                    type: 'POST',
                    data: {
                        token: '<?php echo newToken(); ?>',
                        filename: file.name,
                        filesize: file.size
                    },
                    success: function(response) {
                        checkCount++;
                        
                        try {
                            var result = JSON.parse(response);
                            if (result.status === 'success') {
                                if (result.isDuplicate) {
                                    duplicateCount++;
                                    showNotification('<i class="fas fa-exclamation-circle"></i> ' + '<?php echo $langs->trans('FileAlreadyUploaded'); ?>: ' + file.name, 'warning');
                                    // Update preview to show duplicate
                                    updatePreviewToDuplicate(index);
                                } else {
                                    nonDuplicateFiles.push({file: file, index: index});
                                }
                            } else {
                                // Error checking, include file to be safe
                                nonDuplicateFiles.push({file: file, index: index});
                            }
                        } catch (e) {
                            // Error parsing, include file to be safe
                            nonDuplicateFiles.push({file: file, index: index});
                        }
                        
                        // If all files have been checked, call the callback
                        if (checkCount === files.length) {
                            if (duplicateCount > 0) {
                                showNotification('<i class="fas fa-info-circle"></i> ' + duplicateCount + ' <?php echo $langs->trans('DuplicateFilesSkipped'); ?>', 'warning');
                            }
                            callback(nonDuplicateFiles);
                        }
                    },
                    error: function() {
                        checkCount++;
                        // Error checking, include file to be safe
                        nonDuplicateFiles.push({file: file, index: index});
                        
                        // If all files have been checked, call the callback
                        if (checkCount === files.length) {
                            callback(nonDuplicateFiles);
                        }
                    }
                });
            })(files[i], i);
        }
    }
    
    // Update preview to show file is duplicate
    function updatePreviewToDuplicate(index) {
        var previewItems = uploadPreviews.querySelectorAll('.upload-preview-item');
        if (index < previewItems.length) {
            var previewItem = previewItems[index];
            previewItem.classList.add('duplicate');
            
            var progressBar = previewItem.querySelector('.preview-progress');
            progressBar.innerHTML = '<div class="duplicate-label"><?php echo $langs->trans('Duplicate'); ?></div>';
        }
    }
    
    // Upload non-duplicate files
    function uploadFiles(fileInfos) {
        if (fileInfos.length === 0) return;
        
        var formData = new FormData();
        
        // Add files to FormData
        for (var i = 0; i < fileInfos.length; i++) {
            var fileInfo = fileInfos[i];
            formData.append('userfile[]', fileInfo.file);
            
            // Update progress to show upload starting
            updatePreviewProgress(fileInfo.index, 5);
        }
        
        // Add CSRF token
        formData.append('token', '<?php echo newToken(); ?>');
        
        // Show upload notification
        showNotification('<i class="fas fa-spinner fa-spin"></i> ' + '<?php echo $langs->trans('UploadingFiles'); ?>', 'info');
        
        // Send files to server
        $.ajax({
            url: '<?php echo dol_buildpath('/upinvoice/ajax/upload.php', 1); ?>',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function() {
                var xhr = new XMLHttpRequest();
                
                // Add progress event listener
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        var percentComplete = Math.round((e.loaded / e.total) * 100);
                        
                        // Update all file previews with progress
                        for (var i = 0; i < fileInfos.length; i++) {
                            updatePreviewProgress(fileInfos[i].index, percentComplete);
                        }
                    }
                }, false);
                
                return xhr;
            },
            success: function(response) {
                try {
                    var result = JSON.parse(response);
                    if (result.status === 'success') {
                        showNotification('<i class="fas fa-check-circle"></i> ' + '<?php echo $langs->trans('UploadComplete'); ?>', 'success');
                        
                        // Update preview status for each file
                        for (var i = 0; i < result.files.length; i++) {
                            var fileResult = result.files[i];
                            var fileIndex = -1;
                            
                            // Find the corresponding file index
                            for (var j = 0; j < fileInfos.length; j++) {
                                if (fileInfos[j].file.name === fileResult.name) {
                                    fileIndex = fileInfos[j].index;
                                    break;
                                }
                            }
                            
                            if (fileIndex !== -1) {
                                if (fileResult.status === 'success') {
                                    // Update progress to 100%
                                    updatePreviewProgress(fileIndex, 100);
                                    
                                    // Add success indicator
                                    var previewItems = uploadPreviews.querySelectorAll('.upload-preview-item');
                                    if (fileIndex < previewItems.length) {
                                        previewItems[fileIndex].classList.add('success');
                                    }
                                } else {
                                    // Add error indicator
                                    var previewItems = uploadPreviews.querySelectorAll('.upload-preview-item');
                                    if (fileIndex < previewItems.length) {
                                        previewItems[fileIndex].classList.add('error');
                                        
                                        // Show error message
                                        var progressBar = previewItems[fileIndex].querySelector('.preview-progress');
                                        progressBar.innerHTML = '<div class="error-label">' + fileResult.message + '</div>';
                                    }
                                }
                            }
                        }
                        
                        // Load updated files list after a short delay
                        setTimeout(function() {
                            loadFilesList();
                        }, 1000);
                    } else {
                        showNotification('<i class="fas fa-exclamation-circle"></i> ' + result.message, 'error');
                    }
                } catch (e) {
                    showNotification('<i class="fas fa-exclamation-circle"></i> ' + '<?php echo $langs->trans('ErrorProcessingResponse'); ?>', 'error');
                    console.error('Error parsing response', e);
                }
            },
            error: function(xhr, status, error) {
                showNotification('<i class="fas fa-exclamation-circle"></i> ' + '<?php echo $langs->trans('UploadFailed'); ?>: ' + error, 'error');
                console.error('Upload failed', error);
            }
        });
    }
    
    // Register the loadFilesList function globally so it can be called from other scripts
    upinvoiceLoadFilesListFunction = loadFilesList;
    
    // Initial load of files list
    loadFilesList();
});
</script>
<?php
// Footer
llxFooter();
$db->close();