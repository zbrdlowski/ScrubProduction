var ids = {};
var countInterval;
$(function() {
    $('#posts .age-count').each(function() {
        ids[$(this).attr('data-id')] = $(this).text()

    })
    countInterval = setInterval(() => {
        update_count()
    }, 500)
    update_count()
})

function update_count() {
    ids = {}
    $('#posts .age-count').each(function() {
        ids[$(this).attr('data-id')] = $(this).text()

    })
    if (ids.length <= 0) {
        // Stop Interval if no post/content
        clearInterval(countInterval)
        return false;
    }
    $.ajax({
        url: 'get_count.php',
        method: 'POST',
        data: { ids: ids },
        dataType: 'json',
        error: err => {
            console.log(err)
            alert("An error occured while updating the content views")
            clearInterval(countInterval)
        },
        success: function(resp) {
            if (resp.length > 0) {
                Object.keys(resp).map(k => {
                    $('#posts .views-count[data-id="' + resp[k].id + '"]').text(resp[k].count)
                })
                sort_element()
            }
        }
    })
}

function sort_element() {
    var sorted = $($('#posts .post-item').toArray().sort(function(a, b) {
        var Aelement = a.getElementsByClassName('age-count')[0].innerText,
            Belement = b.getElementsByClassName('age-count')[0].innerText;
        Aelement = parseFloat(Aelement.replace(/\,/gi, ''));
        Belement = parseFloat(Belement.replace(/\,/gi, ''));
        return Aelement - Belement;
    }))

    Object.keys(sorted).map(k => {
        if (typeof sorted[k] == 'object') {
            var el = $(sorted[k]).clone()
            $(sorted[k]).remove()
            $('#posts').prepend(el)
            el.show('slow')
        }
    })
}