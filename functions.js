

/* Timer params */
var digiid_timers_started = false;
var digiid_timers = [];
var digiid_old_href = false;


/* Detect show or hide QR, start or stop timer */
function digiid_qr_change_visibility(new_state = null)
{
    // Autodetect
    if (new_state == null)
    {
        if (!document.getElementById('digiid_addr'))
            new_state = 'show';
        else if (document.getElementById('digiid_addr').value == '')
            new_state = 'show';
        else
            new_state = 'hide';
    }

    // Change state
    var digiid_btn_showqr = document.getElementById('digiid_btn_showqr')
    var registerform = document.getElementById('registerform')
    if (new_state == 'hide') 
    {
        // Stop timer
        digiid_stop_timer();

        // Will work with our block
        var digiid_block = document.getElementById('digiid')

        // Not hide on login, only change opacity
        if (digiid_config['action'] == 'login')
        {
            // Make opacity and change link to refresh page
            if (digiid_block)
            {
                digiid_block.style.opacity = "0.1"
                digiid_old_href = document.querySelector('#digiid_qr a').href
                document.querySelector('#digiid_qr a').href = 'javascript:digiid_reload();'; 
                document.querySelector('#digiid_qr a img').title = 'Click to generate new QR code'; 
            }

            qr = document.getElementById("qr")
            if (qr) qr.parentElement.href = window.location;
        }
        else
        {
            // Hide QR and show btn
            if (digiid_block) digiid_block.style.display = 'none';
            if (digiid_btn_showqr) digiid_btn_showqr.style.display = '';

            // Remove margin
            if (registerform) registerform.style['margin-top'] = 0
        }

        // Hide progress
        document.getElementById('digiid_progress_full').style.display = 'none'
    }
    else
    {
        // Run timer, if it's does not worked yet
        digiid_start_timer();

        // Hide btn
        if (digiid_btn_showqr) digiid_btn_showqr.style.display = 'none';

        // Restore margin
        if (registerform) registerform.style['margin-top'] = ''

        // Show progress
        document.getElementById('digiid_progress_full').style.display = ''
    }
}


/* Reload page */
function digiid_reload()
{
    window.location = window.location
}

/* Clear field and show QR */
function digiid_clear_qr()
{
    // Clear field
    let digiid_addr = document.getElementById('digiid_addr')
    // On registration
    if (digiid_addr) digiid_addr.value = '';

    // Show QR 
    digiid_qr_change_visibility ('show'); 
}


/* Start timer */
function digiid_start_timer()
{
    // Show in full height
    el = document.getElementById('digiid');
    if (el)
    {
        // Show all
        el.style.display = '';
        el.style.opacity = '';

        // Restore link, if saved
        if (digiid_old_href) document.querySelector('#digiid_qr a').href = digiid_old_href; 
    }

    // Already runned?
    if (digiid_timers_started) return false;

    // Starting timers
    digiid_timers_started = Math.floor(Date.now() / 1000);
    digiid_timers['timeout'] = setTimeout("digiid_qr_change_visibility('hide')", 2*60*1000 + 50); // 2 min
    digiid_timers['next_check'] = setInterval(digiid_load_ajax, 4*1000); // 4 sec
    digiid_timers['tick'] = setInterval("digiid_tick(120)", 4*1000); // 1 sec

    // Also additional immediately at start
    digiid_load_ajax();

    return true;
}


/* Update progress bar */
function digiid_tick(max)
{
    if (!digiid_timers_started) return;

    now = Math.floor(Date.now() / 1000);
    procents = (now - digiid_timers_started) / max * 100;

    // Skip it
    if (procents < 3) return;

    // 100% - end
    if (procents > 100) {
        procents = 100;
        clearInterval(digiid_timers['tick']);
        delete(digiid_timers['tick']);
    }

    document.getElementById('digiid_progress_bar').style.width = (100-procents) + '%';
}


/* Stop timer */
function digiid_stop_timer()
{
    // Already stopped
    if (!digiid_timers_started) return false;

    // Stop old timeout timers, if any
    timer_destructor = {'timeout': clearTimeout, 'next_check': clearInterval, 'tick': clearInterval}
    for (item in timer_destructor)
    {
        // If timer is not active - skip
        if (!(item in digiid_timers)) continue;

        // Run destructor on this timer
        timer_destructor[item].call ( this, digiid_timers[item] )
        delete( digiid_timers[item] )
    }
    //if ('next_check' in digiid_timers) clearInterval(digiid_timers['next_check']);
    //if ('tick' in digiid_timers) clearInterval(digiid_timers['tick']);

    digiid_timers_started = false;
    return true;
}


/* Refresh QR state */
function digiid_load_ajax (action='', ajax_url='')
{
    // Timers do not work now    
    if (!digiid_timers_started) return;

    if (action == '') action = digiid_config['action'];
    if (ajax_url == '') ajax_url = digiid_config['ajax_url'];

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
                digiid_after_ajax(action, json);
            }
        }
    ajax.send();
}


/* Analize response of ajax request  */
function digiid_after_ajax (action, json)
{
    // Register
    if (action == 'register') 
    {
        if (json.address > '')
        {
            digiid_qr_change_visibility('hide');
            document.getElementById('digiid_addr').value = json.address;
        }

        return;
    }

    // Login
    if(json.html > '')
    {
        el = document.getElementById('digiid_msg');
        if (el)
        {
            el.innerHTML = json.html;
            el.classList.add('message');
        }
    }

    if(json.stop > 0)
    {
        digiid_qr_change_visibility('hide');
    }

    if(json.reload > 0)
    {
        var redirect = document.getElementsByName("redirect_to");
        if(redirect && redirect[0].value > '')
        {
            window.location.href = redirect[0].value;
        }
        else
        {
            window.location.href = "wp-admin/";
        }
    }
}


// copy yo clipboard
function digiid_copyToClipboard (str) 
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
function digiid_onload_add (event) 
{
    if (window.onload)
        window.onload = window.onload + event;
    else
        window.onload = event;
}

