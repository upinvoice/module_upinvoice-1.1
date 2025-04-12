/**
 * JavaScript functions for UpInvoice Import module
 */

// Global variable for loadFilesList function
var upinvoiceLoadFilesListFunction;

// Flag to track if processing is currently active
var isProcessingActive = false;

// Flag to track if a delete confirmation is active
var isDeleteConfirmationActive = false;

// Track AJAX requests so they can be aborted if needed
var activeAjaxRequests = {};

// Show notification message
function showNotification(message, type = 'info', container = '#upload-results') {
    const alertClass = type === 'success' ? 'alert-success' : 
                        type === 'error' ? 'alert-danger' : 
                        type === 'warning' ? 'alert-warning' : 'alert-info';
    
    const alertHtml = `
        <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    `;
    
    $(container).html(alertHtml);
    
    // Auto-dismiss after 5 seconds
    setTimeout(() => {
        $(container).find('.alert').fadeOut(500, function() {
            $(this).remove();
        });
    }, 15000);
}

// Format file size in human-readable format
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// Create a progress bar element
function createProgressBar(percentage) {
    return `
        <div class="progress">
            <div class="progress-bar" role="progressbar" style="width: ${percentage}%;" 
                aria-valuenow="${percentage}" aria-valuemin="0" aria-valuemax="100">${percentage}%</div>
        </div>
    `;
}

// Update progress bar
function updateProgressBar(selector, percentage) {
    $(selector).find('.progress-bar').css('width', percentage + '%');
    $(selector).find('.progress-bar').attr('aria-valuenow', percentage);
    $(selector).find('.progress-bar').text(percentage + '%');
}

// Process file function
function processFile(fileId, callback) {
    // Set the global processing flag
    isProcessingActive = true;
    
    var $button = $('.process-file-btn[data-file-id="' + fileId + '"]');
    var $status = $('#file-status-' + fileId);
    var $progressContainer = $('#file-progress-' + fileId);
    
    // Update button and status
    $button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
    $status.html('<i class="fas fa-spinner fa-spin"></i> ' + upinvoiceimport_langs.Processing);
    
    // Create progress bar if it doesn't exist
    if ($progressContainer.length === 0) {
        // Buscar el div file-card que contiene el botón
        var $fileCard = $button.closest('.file-card');
        // Añadir el contenedor de progreso después del divider
        $fileCard.find('.file-card-divider').after('<div id="file-progress-' + fileId + '" class="file-progress">' + createProgressBar(0) + '</div>');
        $progressContainer = $('#file-progress-' + fileId);
    } else {
        $progressContainer.html(createProgressBar(0));
        $progressContainer.show();
    }
    
    // Show processing notification
    showNotification('<i class="fas fa-spinner fa-spin"></i> ' + upinvoiceimport_langs.Processing, 'info');
    
    // Simulate progress updates (since the actual API doesn't provide progress events)
    var progressInterval = simulateProgress(fileId);
    
    // Send request to process file
    var jqXHR = $.ajax({
        url: upinvoiceimport_root + '/ajax/process_file.php',
        type: 'POST',
        data: {
            file_id: fileId,
            token: upinvoiceimport_token
        },
        success: function(response) {
            // Clear progress simulation
            clearInterval(progressInterval);

            var $fileCard = $button.closest('.file-card');
            $fileCard.find('.file-error').remove();
            
            try {
                var result = JSON.parse(response);
                if (result.status === 'success') {
                    // Complete progress bar
                    updateProgressBar('#file-progress-' + fileId, 100);
                    
                    // Update status
                    $status.html('<span class="badge badge-processed">' + upinvoiceimport_langs.Processed + '</span>');
                    
                    // Show success notification
                    showNotification('<i class="fas fa-check-circle"></i> ' + upinvoiceimport_langs.FileProcessedSuccessfully, 'success');
                    
                    // Replace the actions div with new buttons
                    var $actionsDiv = $button.closest('.file-actions');
                    $actionsDiv.html('<a href="' + upinvoiceimport_root + '/supplier.php?file_id=' + fileId + '" class="btn btn-success btn-sm"><i class="fas fa-arrow-right"></i> ' + upinvoiceimport_langs.NextStep + '</a>' +
                       ' <button class="btn btn-danger btn-sm delete-file-btn" data-file-id="' + fileId + '"><i class="fas fa-trash"></i></button>');
                    
                    // Re-attach delete event handler
                    $actionsDiv.find('.delete-file-btn').on('click', function(e) {
                        e.preventDefault();
                        var fileId = $(this).data('file-id');
                        confirmDeleteFile(fileId);
                    });
                    
                    // Hide progress bar after a delay
                    setTimeout(function() {
                        $progressContainer.fadeOut();
                    }, 2000);
                    
                    // Release the processing flag
                    isProcessingActive = false;
                    
                    // Find and process next file
                    findAndProcessNextFile();
                } else {
                    // Update status with error
                    $status.html('<span class="badge badge-error">' + upinvoiceimport_langs.Error + '</span>');
                    
                    // Show error message
                    var $fileCard = $button.closest('.file-card');
                    // Eliminar cualquier mensaje de error previo
                    $fileCard.find('.file-error').remove();
                    // Añadir el nuevo mensaje de error
                    $fileCard.find('.file-card-divider').after('<div class="file-error"><span class="text-danger"><i class="fas fa-exclamation-circle"></i> ' + result.message + '</span></div>');
                    
                    $button.prop('disabled', false).html('<i class="fas fa-redo"></i> ' + upinvoiceimport_langs.Retry);
                    showNotification('<i class="fas fa-exclamation-circle"></i> ' + result.message, 'error');
                    
                    // Remove progress bar
                    $progressContainer.remove();
                    
                    // Release the processing flag
                    isProcessingActive = false;
                    
                    // Find and process next file - only process next file if this one failed
                    findAndProcessNextFile();
                }
            } catch (e) {
                // Update status with error
                $status.html('<span class="badge badge-error">' + upinvoiceimport_langs.Error + '</span>');
                $button.prop('disabled', false).html('<i class="fas fa-redo"></i> ' + upinvoiceimport_langs.Retry);
                showNotification('<i class="fas fa-exclamation-circle"></i> ' + upinvoiceimport_langs.ErrorProcessingResponse, 'error');
                console.error('Error parsing response', e);
                
                // Remove progress bar
                $progressContainer.remove();
                
                // Release the processing flag
                isProcessingActive = false;
                
                // Find and process next file
                findAndProcessNextFile();
            }
            
            // Eliminar del registro de solicitudes activas
            delete activeAjaxRequests[fileId];
            
            // Execute callback if provided
            if (typeof callback === 'function') {
                callback(fileId);
            }
            
            // Refresh the file list
            if (typeof upinvoiceLoadFilesListFunction === 'function') {
                setTimeout(function() {
                    upinvoiceLoadFilesListFunction();
                }, 1000);
            }
        },
        error: function(xhr, status, error) {
            // Si es un aborto, no mostrar como error
            if (status === 'abort') {
                console.log('Request aborted for file ' + fileId);
                
                // Clear progress simulation
                clearInterval(progressInterval);
                
                // Eliminar del registro de solicitudes activas
                delete activeAjaxRequests[fileId];
                
                // Execute callback if provided
                if (typeof callback === 'function') {
                    callback(fileId);
                }
                
                // Release the processing flag
                isProcessingActive = false;
                
                return;
            }
            
            // Clear progress simulation
            clearInterval(progressInterval);
            
            // Update status with error
            $status.html('<span class="badge badge-error">' + upinvoiceimport_langs.Error + '</span>');
            $button.prop('disabled', false).html('<i class="fas fa-redo"></i> ' + upinvoiceimport_langs.Retry);
            showNotification('<i class="fas fa-exclamation-circle"></i> ' + upinvoiceimport_langs.ProcessingFailed + ': ' + error, 'error');
            console.error('Processing failed', error);

            // Eliminar cualquier mensaje de error previo
            var $fileCard = $button.closest('.file-card');
            $fileCard.find('.file-error').remove();
            // Añadir el nuevo mensaje de error
            $fileCard.find('.file-card-divider').after('<div class="file-error"><span class="text-danger"><i class="fas fa-exclamation-circle"></i> ' + upinvoiceimport_langs.ProcessingFailed + ': ' + error + '</span></div>');     
            
            // Remove progress bar
            $progressContainer.remove();
            
            // Eliminar del registro de solicitudes activas
            delete activeAjaxRequests[fileId];
            
            // Execute callback if provided
            if (typeof callback === 'function') {
                callback(fileId);
            }
            
            // Release the processing flag
            isProcessingActive = false;
            
            // Find and process next file
            findAndProcessNextFile();
        }
    });
    
    // Almacenar la referencia a la solicitud AJAX
    activeAjaxRequests[fileId] = jqXHR;
}

// Función para encontrar y procesar el siguiente archivo pendiente
function findAndProcessNextFile() {
    // Si ya hay un proceso activo, no hacemos nada
    if (isProcessingActive) {
        return;
    }
    
    // Buscar todos los archivos pendientes que no tengan error
    var pendingFiles = [];
    var processingButtons = $('.process-file-btn');
    
    // Si no hay botones de procesamiento, significa que no hay archivos pendientes
    if (processingButtons.length === 0) {
        return;
    }
    
    processingButtons.each(function() {
        // Comprobar si el botón está habilitado (no está siendo procesado)
        if (!$(this).prop('disabled')) {
            var fileId = $(this).data('file-id');
            
            // Comprobar si el archivo no tiene error
            var $status = $('#file-status-' + fileId);
            if (!$status.find('.badge-error').length) {
                pendingFiles.push(fileId);
            }
        }
    });
    
    // Si hay archivos pendientes, procesar el primero
    if (pendingFiles.length > 0) {
        processFile(pendingFiles[0]);
    }
}

// Actualizar la función de simulación de progreso para trabajar con la nueva estructura
function simulateProgress(fileId) {
    var progress = 0;
    var $progressContainer = $('#file-progress-' + fileId);
    
    return setInterval(function() {
        // Random progress increment between 5 and 15 percent
        var increment = Math.floor(Math.random() * 10) + 5;
        
        // Calculate new progress, ensuring it doesn't exceed 95% (100% is set upon completion)
        progress = Math.min(95, progress + increment);
        
        // Update progress bar
        updateProgressBar('#file-progress-' + fileId, progress);
    }, 1000); // Update every second
}

// Go to next step (supplier validation)
function goToNextStep(fileId) {
    window.location.href = upinvoiceimport_root + '/supplier.php?file_id=' + fileId;
}

// Handle file delete confirmation
function confirmDeleteFile(fileId, callback) {
    // Prevenir múltiples confirmaciones simultáneas
    if (isDeleteConfirmationActive) {
        return;
    }
    
    isDeleteConfirmationActive = true;
    
    if (confirm(upinvoiceimport_langs.ConfirmDeleteFile)) {
        $.ajax({
            url: upinvoiceimport_root + '/ajax/delete_file.php',
            type: 'POST',
            data: {
                file_id: fileId,
                token: upinvoiceimport_token
            },
            success: function(response) {
                isDeleteConfirmationActive = false;
                try {
                    const result = JSON.parse(response);
                    if (result.status === 'success') {
                        showNotification(result.message, 'success');
                        
                        // Always refresh the files list if we have a global reference to the loadFilesList function
                        if (typeof upinvoiceLoadFilesListFunction === 'function') {
                            upinvoiceLoadFilesListFunction();
                        }
                        
                        if (typeof callback === 'function') {
                            callback(true);
                        }
                    } else {
                        showNotification(result.message, 'error');
                        if (typeof callback === 'function') {
                            callback(false);
                        }
                    }
                } catch (e) {
                    showNotification(upinvoiceimport_langs.ErrorProcessingResponse, 'error');
                    if (typeof callback === 'function') {
                        callback(false);
                    }
                }
            },
            error: function(xhr, status, error) {
                isDeleteConfirmationActive = false;
                showNotification(upinvoiceimport_langs.DeleteFailed + ': ' + error, 'error');
                if (typeof callback === 'function') {
                    callback(false);
                }
            }
        });
    } else {
        isDeleteConfirmationActive = false;
        if (typeof callback === 'function') {
            callback(false);
        }
    }
}

// Toggle file preview modal usando el nuevo sistema de modales
function toggleFilePreview(fileId, fileName, fileType, filePath) {
    UpInvoiceModal.showDocumentPreview(filePath, fileType, fileName);
}

// Function to create a thumbnail for file preview
function createThumbnail(fileId, fileType, filePath) {
    var thumbnailHtml = '';
    
    if (fileType.includes('pdf')) {
        thumbnailHtml = `<div class="file-thumbnail pdf-thumbnail" data-file-id="${fileId}" data-file-path="${filePath}">
            <i class="fas fa-file-pdf fa-3x"></i>
        </div>`;
    } else if (fileType.includes('image')) {
        thumbnailHtml = `<div class="file-thumbnail image-thumbnail" data-file-id="${fileId}" data-file-path="${filePath}">
            <img src="${filePath}" class="thumbnail-image">
        </div>`;
    } else {
        thumbnailHtml = `<div class="file-thumbnail" data-file-id="${fileId}" data-file-path="${filePath}">
            <i class="fas fa-file fa-3x"></i>
        </div>`;
    }
    
    return thumbnailHtml;
}

/**
 * Sistema unificado para gestionar modales con jQuery UI
 */
var UpInvoiceModal = {
    /**
     * Inicializa el sistema de modales
     */
    init: function() {
        // Crear el div de diálogo si no existe
        if ($('#upinvoice-dialog-container').length === 0) {
            $('body').append('<div id="upinvoice-dialog-container" style="display:none;"></div>');
        }
        
        // Añadir manejadores de eventos para los botones que abren modales
        this.setupEventHandlers();
    },
    
    /**
     * Configurar manejadores de eventos
     */
    setupEventHandlers: function() {
        // Botón de vista previa de documento
        $(document).on('click', '#preview-doc-btn, .file-thumbnail', function(e) {
            e.preventDefault();
            
            var fileId = $(this).data('file-id') || '';
            var fileName = $(this).data('file-name') || 'Documento';
            var fileType = $(this).data('file-type') || '';
            var filePath = $(this).data('file-path') || '';
            
            // Si no hay ruta pero tenemos un botón de vista previa, intentar obtenerla del modal existente
            if (!filePath && $(this).attr('id') === 'preview-doc-btn') {
                var $modal = $('.upinvoiceimport-modal');
                if ($modal.length) {
                    var $iframe = $modal.find('.upinvoiceimport-pdf-preview');
                    if ($iframe.length) {
                        filePath = $iframe.attr('src');
                        fileType = 'application/pdf';
                    }
                    
                    var $img = $modal.find('.upinvoiceimport-img-preview');
                    if ($img.length) {
                        filePath = $img.attr('src');
                        fileType = 'image/jpeg'; // Asumimos jpeg por defecto
                    }
                    
                    fileName = $modal.find('h2').text() || 'Vista previa';
                }
            }
            
            // También intentar obtener la ruta desde los inputs ocultos en caso de estar disponibles
            if (!filePath) {
                var hiddenPath = $('#document-preview-path').val();
                var hiddenType = $('#document-preview-type').val();
                if (hiddenPath) {
                    filePath = hiddenPath;
                    fileType = hiddenType || fileType;
                }
            }
            
            if (filePath) {
                UpInvoiceModal.showDocumentPreview(filePath, fileType, fileName);
            }
        });
    },
    
    /**
     * Muestra la vista previa de un documento en un diálogo
     * @param {string} url - URL del documento
     * @param {string} type - Tipo MIME del documento
     * @param {string} title - Título del diálogo
     */
    showDocumentPreview: function(url, type, title) {
        var $dialogContainer = $('#upinvoice-dialog-container');
        var dialogContent = '';
        var dialogButtons = {};
        
        // Determinar contenido según el tipo de archivo
        if (type && type.indexOf('pdf') !== -1) {
            dialogContent = '<iframe src="' + url + '" style="width:100%; height:100%; border:none;"></iframe>';
        } else if (type && (type.indexOf('image') !== -1 || url.match(/\.(jpe?g|png|gif|bmp|webp)$/i))) {
            dialogContent = '<img src="' + url + '" style="max-width:100%; max-height:90vh; margin:0 auto; display:block;" />';
            
            // Para imágenes, añadimos botones específicos
            var rotation = 0;
            
            dialogButtons = {
                "Tamaño original": function() {
                    $(this).find('img').css({
                        'max-height': 'none',
                        'max-width': 'none'
                    });
                },
                "Girar 90°": function() {
                    rotation += 90;
                    $(this).find('img').css('transform', 'rotate(' + rotation + 'deg)');
                },
                "Cerrar": function() {
                    $(this).dialog('close');
                }
            };
        } else {
            dialogContent = '<div class="error">Vista previa no disponible</div>';
            dialogButtons = {
                "Cerrar": function() {
                    $(this).dialog('close');
                }
            };
        }
        
        // Establecer el contenido del diálogo
        $dialogContainer.html(dialogContent);
        
        // Calcular dimensiones adecuadas para el diálogo
        var winWidth = $(window).width();
        var winHeight = $(window).height();
        var dialogWidth = Math.min(winWidth * 0.9, 900);
        var dialogHeight = Math.min(winHeight * 0.9, 700);
        
        // Mostrar el diálogo
        $dialogContainer.dialog({
            title: title || 'Vista previa',
            width: dialogWidth,
            height: dialogHeight,
            modal: true,
            draggable: true,
            resizable: true,
            buttons: dialogButtons,
            close: function() {
                $(this).html(''); // Limpiar contenido al cerrar
            }
        });
        
        // Ajustes específicos para PDFs
        if (type && type.indexOf('pdf') !== -1) {
            $dialogContainer.css('padding', '0');
            $dialogContainer.parent().find('.ui-dialog-buttonpane').css('margin-top', '0');
        }
    }
};

// Function to load and display files list
function loadFilesList() {
    // Mostrar un indicador de carga pero sin eliminar el contenido existente
    var loadingIndicator = '<div class="info-box" style="margin-bottom:10px;"><i class="fas fa-sync fa-spin"></i> ' + 
                          upinvoiceimport_langs.Loading + '...</div>';
    
    if (upinvoiceimport_active_tab === 'pending') {
        // Añadir el indicador sin eliminar el contenido existente
        $('.info-box').remove(); // Eliminar cualquier indicador previo
        $('#pending-files-list').prepend(loadingIndicator);
    } else {
        // Para la tabla de finalizados, podemos añadir una fila con el indicador
        $('.loading-indicator').remove(); // Eliminar cualquier indicador previo
        $('#finished-files-list').prepend('<tr class="loading-indicator"><td colspan="8" class="center"><i class="fas fa-sync fa-spin"></i> ' + 
                                        upinvoiceimport_langs.Loading + '...</td></tr>');
    }
    
    $.ajax({
        url: upinvoiceimport_root + '/ajax/list_files.php',
        type: 'GET',
        data: {
            token: upinvoiceimport_token,
            file_type: upinvoiceimport_active_tab // 'pending' or 'finished'
        },
        success: function(response) {
            try {
                // Eliminar indicadores de carga
                $('.info-box, .loading-indicator').remove();
                
                var result = JSON.parse(response);
                if (result.status === 'success') {
                    // Solo reemplazar el contenido si recibimos HTML válido
                    if (result.html && result.html.trim() !== '' && result.html !== 'undefined') {
                        if (upinvoiceimport_active_tab === 'pending') {
                            $('#pending-files-list').html(result.html);
                        } else {
                            $('#finished-files-list').html(result.html);
                        }
                        
                        // Setup process button events - Use delegated events to avoid duplicates
                        $('#pending-files-list').off('click', '.process-file-btn').on('click', '.process-file-btn', function() {
                            var fileId = $(this).data('file-id');
                            if (!isProcessingActive) {
                                processFile(fileId);
                            } else {
                                showNotification('<i class="fas fa-info-circle"></i> ' + upinvoiceimport_langs.ProcessingInProgress, 'info');
                            }
                        });
                        
                        // Setup delete button events - Use delegated events to avoid duplicates
                        $('#pending-files-list, #finished-files-list').off('click', '.delete-file-btn').on('click', '.delete-file-btn', function(e) {
                            e.preventDefault();
                            var fileId = $(this).data('file-id');
                            confirmDeleteFile(fileId);
                        });
                        
                        // Setup thumbnail click events - Use delegated events
                        $('#pending-files-list, #finished-files-list').off('click', '.file-thumbnail').on('click', '.file-thumbnail', function() {
                            var fileId = $(this).data('file-id');
                            var fileName = $(this).data('file-name') || upinvoiceimport_langs.FilePreview;
                            var fileType = $(this).data('file-type') || '';
                            var filePath = $(this).data('file-path');
                            
                            toggleFilePreview(fileId, fileName, fileType, filePath);
                        });
                        
                        // Start automatic processing if we're in the pending tab and not already processing
                        if (upinvoiceimport_active_tab === 'pending' && !isProcessingActive) {
                            findAndProcessNextFile();
                        }
                    }
                } else {
                    // Si hay un error, mostrarlo pero mantener el contenido anterior
                    var errorMessage = '<div class="error-notification"><i class="fas fa-exclamation-circle"></i> ' + 
                        (result.message || upinvoiceimport_langs.ErrorProcessingResponse) + '</div>';
                    
                    if (upinvoiceimport_active_tab === 'pending') {
                        $('#pending-files-list').prepend(errorMessage);
                    } else {
                        $('#finished-files-list').prepend('<tr><td colspan="8" class="error-notification">' + 
                            errorMessage + '</td></tr>');
                    }
                }
            } catch (e) {
                console.error('Error parsing response', e);
                // Mostrar mensaje de error pero mantener el contenido anterior
                var errorMessage = '<div class="error-notification"><i class="fas fa-exclamation-circle"></i> ' + 
                    upinvoiceimport_langs.ErrorProcessingResponse + '</div>';
                
                if (upinvoiceimport_active_tab === 'pending') {
                    $('#pending-files-list').prepend(errorMessage);
                } else {
                    $('#finished-files-list').prepend('<tr><td colspan="8" class="error-notification">' + 
                        errorMessage + '</td></tr>');
                }
                
                // Eliminar los indicadores de carga
                $('.info-box, .loading-indicator').remove();
            }
        },
        error: function(xhr, status, error) {
            console.error('Load files failed', error);
            // Mostrar mensaje de error pero mantener el contenido anterior
            var errorMessage = '<div class="error-notification"><i class="fas fa-exclamation-circle"></i> ' + 
                upinvoiceimport_langs.LoadFilesFailed + ': ' + error + '</div>';
            
            if (upinvoiceimport_active_tab === 'pending') {
                $('#pending-files-list').prepend(errorMessage);
            } else {
                $('#finished-files-list').prepend('<tr><td colspan="8" class="error-notification">' + 
                    errorMessage + '</td></tr>');
            }
            
            // Eliminar los indicadores de carga
            $('.info-box, .loading-indicator').remove();
        }
    });
}

// Document ready handler
$(document).ready(function() {
    // Initialize modal system
    UpInvoiceModal.init();
    
    // Register the loadFilesList function globally so it can be called from other scripts
    upinvoiceLoadFilesListFunction = loadFilesList;
    
    // Initial load of files list - this will trigger automatic processing if needed
    loadFilesList();
});