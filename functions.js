

/* Timer params */
var digiid_timers_started = {};
var digiid_timers = {};
var digiid_old_href = {};
var digiid_config = {};
var digiid_base_ajax = '';


/* Detect show or hide QR, start or stop timer */
function digiid_qr_change_visibility(id, new_state = null)
{
    if (id == null) {
        for (timer_id in digiid_timers_started)
            digiid_qr_change_visibility(timer_id, new_state)

        return;
    }

    obj = jQuery("#" + id);

    // Autodetect
    if (new_state == null)
    {
        /*if (jQuery("input[name=digiid_addr]").length == 0)
            new_state = 'hide';
        else*/ if (jQuery("input[name=digiid_addr]", obj).length == 0)
            new_state = 'show';
        else
            new_state = 'hide';
    }

    old_state = obj.hasClass('timeout') ? 'hide' : 'show';
    if (old_state == new_state) return;

    if (jQuery('.digiid', obj).length == 0)
        obj = jQuery('body');

    // Show block
    jQuery('.digiid', obj).show()

    // Change state
    var digiid_btn_showqr = jQuery('.digiid_btn_showqr', obj)
    var registerform = jQuery('.registerform', obj)
    if (new_state == 'hide' || new_state == 'complete') 
    {
        // Stop timer if any
        digiid_stop_timer(id)

        // Will work with our block
        var digiid_block = jQuery('.digiid', obj)

        // Make opacity and change link to refresh page
        if (digiid_block.length)
        {
            digiid_old_href[id] = jQuery('.digiid_qr a', obj).attr('href');

            // For complete - special class
            if (new_state == 'complete') {
                digiid_qr_class = 'qr_completed';
                digiid_qr_script = 'return false;';
                digiid_qr_title = '';
            }
            else {
                digiid_qr_class = 'timeout';
                digiid_qr_script = 'digiid_reload("'+id+'")';
                digiid_qr_title = 'Click to generate new QR code';
            }

            digiid_block.parent().addClass(digiid_qr_class);
            jQuery('.digiid_qr a', obj).attr('href', 'javascript:' + digiid_qr_script);
            jQuery('.digiid_qr a img', obj).attr('title', digiid_qr_title);
        }

        jQuery(".qr", obj).parent().attr('href', 'window.location');
        
        // Hide progress
        jQuery('.digiid_progress_full', obj).hide()
    }
    else
    {
        // Run timer, if it's does not worked yet
        digiid_start_timer(id);

        // Recharge progress bar
        jQuery('.digiid_progress_bar', obj).css('width', 100 + '%');

        // Clean message type and content
        obj.next()
            .attr('class', 'digiid_msg')
            .html('');

        // Hide btn
        if (digiid_btn_showqr.length) digiid_btn_showqr.hide()

        // Restore margin
        if (registerform.length) registerform.css('margin-top', '')

        // Show progress
        jQuery('.digiid_progress_full', obj).show ()
    }
}


/* Reload page */
function digiid_reload(id)
{
    // Remove focus
    //jQuery('body').focus()
    // Show
    digiid_qr_change_visibility(id, 'show')
}


function digiid_on_change_reg_input_addr()
{
    let digiid_addr = jQuery('input[name=digiid_addr]').val();

    if (digiid_addr != '') {
        digiid_qr_change_visibility('digiid_1','hide');
        jQuery('#digiid_1').hide();
    } else {
        digiid_qr_change_visibility('digiid_1','show'); 
        jQuery('#digiid_1').show(); 
    }
}


/* Clear field and show QR */
function digiid_clear_qr(id = null)
{
    if (id == null)
        obj = jQuery('body');
    else
        obj = jQuery("#" + id);
    
    // Clear field
    let digiid_addr = jQuery('input[name=digiid_addr]', obj)
    // On registration
    digiid_addr.val('');

    // Show QR 
    //digiid_qr_change_visibility(id, 'show'); 
    digiid_on_change_reg_input_addr();
}


/* Start timer */
function digiid_start_timer(id)
{
    obj = jQuery("#" + id);

    // Show in full height
    el = jQuery('.digiid', obj);
    if (el.length)
    {
        // Remove old timer
        el.parent().removeClass('timeout', 500);

        // Restore link, if saved
        if (digiid_old_href[id]) jQuery('.digiid_qr a', obj).attr('href', digiid_old_href[id]); 
    }

    // Already runned?
    if (digiid_timers_started[id]) return false;

    // Starting timers
    digiid_timers_started[id] = Math.floor(Date.now() / 1000);
    digiid_timers[id] = {}
    digiid_timers[id]['timeout'] = setTimeout("digiid_qr_change_visibility('"+id+"','hide')", 2*60*1000 + 50); // 2 min
    digiid_timers[id]['next_check'] = setInterval("digiid_load_ajax('"+id+"')", 4*1000); // 4 sec
    digiid_timers[id]['tick'] = setInterval("digiid_tick('"+id+"',120)", 4*1000); // 1 sec

    // Also additional immediately at start
    digiid_load_ajax(id);

    return true;
}


/* Update progress bar */
function digiid_tick(id, max)
{
    if (!digiid_timers_started[id]) return;

    now = Math.floor(Date.now() / 1000);
    procents = (now - digiid_timers_started[id]) / max * 100;

    // Skip it
    if (procents < 3) return;

    // 100% - end
    if (procents > 100) {
        procents = 100;
        clearInterval(digiid_timers[id]['tick']);
        delete(digiid_timers[id]['tick']);
    }

    obj = jQuery("#" + id);
    jQuery('.digiid_progress_bar', obj).css('width', (100-procents) + '%');
}


/* Stop timer */
function digiid_stop_timer(id = null)
{
    // Stop all timers
    if (id == null) {
        for (timer_id in digiid_timers_started)
        {
            console.log(timer_id)
            digiid_stop_timer(timer_id)
        }

        return false;
    }

    // Already stopped
    if (!digiid_timers_started[id]) return false;

    // Stop old timeout timers, if any
    timer_destructor = {'timeout': clearTimeout, 'next_check': clearInterval, 'tick': clearInterval}
    for (item in timer_destructor)
    {
        // If timer is not active - skip
        if (!(item in digiid_timers[id])) continue;

        // Run destructor on this timer
        timer_destructor[item].call ( this, digiid_timers[id][item] )
        delete( digiid_timers[id][item] )
    }

    digiid_timers_started[id] = false;
    return true;
}


/* Refresh QR state */
function digiid_load_ajax(id, action='', ajax_url='')
{
    // Timers do not work now
    if (!digiid_timers_started[id]) return;

    if (action == '') action = digiid_config[id]['action'];
    if (ajax_url == '') ajax_url = digiid_config[id]['ajax_url'];

    var ajax = new XMLHttpRequest();
    ajax.open("GET", ajax_url, true);
    ajax.onreadystatechange =
        function ()
        {
            if(ajax.readyState != 4 || ajax.status != 200)
                return;

            if(ajax.responseText != '')
            {
                var json = JSON.parse(ajax.responseText);
                digiid_after_ajax(id, action, json);
            }
        }
    ajax.send();
}


/* Analize response of ajax request  */
function digiid_after_ajax (id, action, json)
{
    obj = jQuery("#" + id);

    // Login
    if(json.html > '')
    {
        // .digiid_msg 
        obj.next().html(json.html).addClass('message')
    }

    // Register
    if (action == 'register') 
    {
        if (json.address > '')
        {
            // Input field not found
            el = jQuery('input[name=digiid_addr]', obj)
            if (el.length == 0)
                el = jQuery('input[name=digiid_addr]')

            // Set
            el.val(json.address);
            digiid_on_change_reg_input_addr();
        }
        return;
    }

    // Myaccount
    if (action == 'wc-myaccount') 
    {
        if (json.status == 2)
        {
            // Hide QR
            digiid_qr_change_visibility(id, 'hide');

            // Working with input field
            jQuery('#digiid_addr')
                // Write address
                .val(json.address)
                // Add highlight
                .addClass('digiid_highlight')
                // Check address is changed manually
                .on('change', function() {
                    if (this.value != json.address)
                        jQuery(this).removeClass('digiid_highlight')
                    else
                        jQuery(this).addClass('digiid_highlight')
                });

            // Will try to focus
            jQuery('#reg_email').focus();
        }
    }

    // Widget
    /*if (action == 'wc-login') 
    {
        if (json.status == 2)
        {
            // .digiid_msg
            obj.next().html('>>>' + json.html).addClass('message')
            console.log (json.html)
            console.log (obj)
            console.log (id)
        }
    }*/

    if (json.stop > 0)
    {
        digiid_qr_change_visibility(id, 'hide');
    }

    if (json.message)
    {
        div_msg = obj.next().html(json.message).addClass('message')
        if (json.message_class) div_msg.addClass(json.message_class)
    }
    else

    if (json.reload > 0)
    {
        // Stop all timers
        digiid_qr_change_visibility(null, 'complete')

        // Detect url to redirect
        var url = window.location.href;
        if (json.redirect_url) url = json.redirect_url; 
        else {
            redirect_input = jQuery('input[name=redirect_to]', obj);
            if (redirect_input.length == 0) redirect_input = jQuery('input[name=redirect_to]');

            if (redirect_input.length && redirect_input.val() != '')
                url = redirect_input.val();
        }
        if (url) window.location.href = url;
    }
}

function digiid_remove_address(el)
{
    digiid_address = el.parentElement.parentElement.textContent.trim();
    digiid_address = digiid_address.split(' ')[0];
    ajax_url = digiid_base_ajax + '&type=del&digiid_addr=' + digiid_address;

    var ajax = new XMLHttpRequest();
    ajax.open("GET", ajax_url, true);
    ajax.onreadystatechange =
        function ()
        {
            if(ajax.readyState != 4 || ajax.status != 200)
                return;

            if(ajax.responseText != '')
            {
                var json = JSON.parse(ajax.responseText);
                if (json.reload) window.location = window.location;
            }
        }
    ajax.send();
}


// copy yo clipboard
function digiid_copyToClipboard(str) 
{
    const el = document.createElement('textarea');  // Create a <textarea> element
    el.value = str;                                 // Set its value to the string that you want copied
    el.setAttribute('readonly', '');                // Make it readonly to be tamper-proof
    el.style.position = 'absolute';                 
        el.style.left = '-9999px';                      // Move outside the screen to make it invisible
    document.body.appendChild(el);                  // Append the <textarea> element to the HTML document
        const selected =            
    document.getSelection().rangeCount > 0        // Check if there is any content selected previously
        ? document.getSelection().getRangeAt(0)     // Store selection if found
        : false;                                    // Mark as false to know no selection existed before
    el.select();                                    // Select the <textarea> content
    document.execCommand('copy');                   // Copy - only works as a result of a user action (e.g. click events)
    document.body.removeChild(el);                  // Remove the <textarea> element
    if (selected) {                                 // If a selection existed before copying
    document.getSelection().removeAllRanges();    // Unselect everything on the HTML document
    document.getSelection().addRange(selected);   // Restore the original selection
    }
    return false;
};


/* On load stack */
function digiid_onload_add(event) 
{
    if (window.onload)
        window.onload = window.onload + event;
    else
        window.onload = event;
}

