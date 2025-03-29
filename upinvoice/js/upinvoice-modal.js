/**
 * Gestión unificada de modales para el módulo UpInvoice
 * Utiliza jQuery UI que ya está incluido en Dolibarr
 */

// Objeto global para gestionar modales
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

// Inicializar cuando el DOM esté listo
$(document).ready(function() {
    UpInvoiceModal.init();
});