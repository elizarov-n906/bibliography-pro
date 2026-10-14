jQuery(function($) {
    
    var lastEditor = null;
    var lastBookmark = null;
    var lastTextarea = null;
    var lastStart = 0;
    var lastEnd = 0;
    
    $(document).on('focus click keyup', '#content, .wp-editor-area', function() {
        lastTextarea = this;
        lastStart = this.selectionStart;
        lastEnd = this.selectionEnd;
    });
    
    if (typeof tinymce !== 'undefined') {
        $(document).on('tinymce-editor-init', function(event, editor) {
            editor.on('focus click keyup', function() {
                lastEditor = editor;
                lastBookmark = editor.selection.getBookmark();
            });
        });
        
        tinymce.on('AddEditor', function(e) {
            e.editor.on('focus click keyup', function() {
                lastEditor = e.editor;
                lastBookmark = e.editor.selection.getBookmark();
            });
        });
    }
    
    function insertTextIntoEditor(text) {
        if (lastEditor && lastBookmark) {
            try {
                lastEditor.focus();
                lastEditor.selection.moveToBookmark(lastBookmark);
                lastEditor.insertContent(text);
                lastBookmark = lastEditor.selection.getBookmark();
                return true;
            } catch(err) {}
        }
        
        if (lastTextarea) {
            var ta = lastTextarea;
            ta.focus();
            ta.selectionStart = lastStart;
            ta.selectionEnd = lastEnd;
            var val = ta.value;
            ta.value = val.substring(0, lastStart) + text + val.substring(lastEnd);
            lastStart = lastEnd = lastStart + text.length;
            ta.selectionStart = ta.selectionEnd = lastStart;
            return true;
        }
        
        if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
            tinymce.activeEditor.focus();
            tinymce.activeEditor.insertContent(text);
            return true;
        }
        
        copyToClipboard(text);
        alert('Скопировано в буфер: ' + text + '\n\nНажмите Ctrl+V в текст статьи');
        return false;
    }
    
    function copyToClipboard(text) {
        var tmp = document.createElement('textarea');
        tmp.value = text;
        tmp.style.position = 'fixed';
        tmp.style.left = '-9999px';
        document.body.appendChild(tmp);
        tmp.select();
        try { document.execCommand('copy'); } catch(e) {}
        document.body.removeChild(tmp);
    }
    
    function addNoteRow(container) {
        var n = container.find('.bp-row').length + 1;
        var html = '<div class="bp-row" data-order="' + n + '" data-type="note">' +
            '<span class="bp-num">' + n + '</span>' +
            '<input type="text" name="bp_notes[]" placeholder="Текст примечания...">' +
            '<button type="button" class="button bp-insert-note" title="Вставить примечание">[прим]</button>' +
            '<button type="button" class="button bp-up">Вверх</button>' +
            '<button type="button" class="button bp-down">Вниз</button>' +
            '<button type="button" class="button-link-delete bp-del">Удалить</button>' +
            '</div>';
        container.append(html);
    }
    
    function addBibRow(container) {
        var n = container.find('.bp-row').length + 1;
        var html = '<div class="bp-row" data-order="' + n + '" data-type="bib">' +
            '<span class="bp-num">' + n + '</span>' +
            '<input type="text" name="bp_authors[]" placeholder="Пример: Кант Э. Собрание сочинений...">' +
            '<input type="hidden" name="bp_linked[]" value="0">' +
            '<button type="button" class="button bp-toggle-link">Ссылка</button>' +
            '<button type="button" class="button bp-insert-cite" title="Вставить ссылку">[+]</button>' +
            '<button type="button" class="button bp-up">Вверх</button>' +
            '<button type="button" class="button bp-down">Вниз</button>' +
            '<button type="button" class="button-link-delete bp-del">Удалить</button>' +
            '</div>';
        container.append(html);
    }
    
    $(document).on('click', '.bp-add-note', function() {
        var container = $(this).siblings('.bp-rows');
        addNoteRow(container);
        bpRenumber();
    });
    
    $(document).on('click', '.bp-add-note-10', function() {
        var container = $(this).siblings('.bp-rows');
        for (var i = 0; i < 10; i++) {
            addNoteRow(container);
        }
        bpRenumber();
    });
    
    $(document).on('click', '.bp-add', function() {
        var container = $(this).siblings('.bp-rows');
        addBibRow(container);
        bpRenumber();
    });
    
    $(document).on('click', '.bp-add-10', function() {
        var container = $(this).siblings('.bp-rows');
        for (var i = 0; i < 10; i++) {
            addBibRow(container);
        }
        bpRenumber();
    });
    
    $(document).on('click', '.bp-insert-cite', function(e) {
        e.preventDefault();
        var num = $(this).closest('.bp-row').data('order');
        insertTextIntoEditor('[' + num + ']');
        flashInserted($(this));
    });
    
    $(document).on('click', '.bp-insert-note', function(e) {
        e.preventDefault();
        var num = $(this).closest('.bp-row').data('order');
        insertTextIntoEditor('{note:' + num + '}');
        flashInserted($(this));
    });
    
    function flashInserted($btn) {
        var original = $btn.text();
        $btn.text('Вставлено').css({
            'background': '#46b450',
            'color': '#fff',
            'border-color': '#46b450'
        });
        setTimeout(function() {
            $btn.text(original).css({
                'background': '',
                'color': '',
                'border-color': ''
            });
        }, 1500);
    }
    
    $(document).on('click', '.bp-toggle-link', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $hidden = $btn.parent().find('input[name="bp_linked[]"]');
        
        if ($btn.hasClass('bp-active')) {
            $btn.removeClass('bp-active').text('Ссылка');
            $hidden.val('0');
        } else {
            $btn.addClass('bp-active').text('Активна');
            $hidden.val('1');
        }
    });
    
    $(document).on('click', '.bp-del', function() {
        $(this).closest('.bp-row').remove();
        bpRenumber();
    });
    
    $(document).on('click', '.bp-up', function() {
        var row = $(this).closest('.bp-row');
        var prev = row.prev('.bp-row');
        if (prev.length) row.insertBefore(prev);
        bpRenumber();
    });
    
    $(document).on('click', '.bp-down', function() {
        var row = $(this).closest('.bp-row');
        var next = row.next('.bp-row');
        if (next.length) row.insertAfter(next);
        bpRenumber();
    });
    
    function bpRenumber() {
        $('.bp-rows').each(function() {
            $(this).find('.bp-row').each(function(i) {
                $(this).find('.bp-num').text(i + 1);
                $(this).attr('data-order', i + 1);
            });
        });
    }
    
    $(document).on('click', '.bp-link', function(e) {
        e.preventDefault();
        var num = $(this).data('num');
        var name = $(this).data('name');
        $('.bp-popup-text').removeClass('bp-note-text').html('<b>' + num + '.</b> ' + name);
        $('.bp-popup').css('display', 'flex').hide().fadeIn(150);
    });
    
    $(document).on('click', '.bp-note-link', function(e) {
        e.preventDefault();
        var num = $(this).data('num');
        var text = $(this).data('text');
        $('.bp-popup-text').addClass('bp-note-text').html('<b>' + num + '.</b> ' + text);
        $('.bp-popup').css('display', 'flex').hide().fadeIn(150);
    });
    
    $(document).on('click', '.bp-popup-close', function() {
        $('.bp-popup').fadeOut(150);
    });
    
    $(document).on('click', '.bp-popup', function(e) {
        if (e.target === this) $(this).fadeOut(150);
    });
    
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') $('.bp-popup').fadeOut(150);
    });
});