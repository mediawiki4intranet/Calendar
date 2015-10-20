// Make POST request to create/edit calendar event article
window.wikiaddevent = function(t, m, s)
{
	var f = document.createElement('form');
	f.action = t;
	f.method = 'POST';
	var i = document.createElement('input');
	i.type = 'hidden';
	i.name = m;
	i.value = s;
	f.appendChild(i);
	if (m != 'wpTextbox1')
	{
		var i = document.createElement('input');
		i.type = 'hidden';
		i.name = 'wpTextbox1';
		i.value = 'ぺ '+mw.msg('calendar-event-created')+' '+mw.config.get('wgUserName');
		f.appendChild(i);
	}
	document.body.appendChild(f);
	f.submit();
	return false;
};

// Show a hint with date's events using AJAX
window.calendarshowdate = function(el, title, date)
{
	var settings = el;
	while (settings && settings.nodeName.toLowerCase() != 'form')
		settings = settings.parentNode;
	if (settings && settings.settings)
		settings = settings.settings.value;
	else
		settings = '';
	el.parentNode.className = el.parentNode.className + ' ydate_shown';
	el.style.display = '';
	if (el.innerHTML != '')
		return;
	$.ajax({
		type: "POST",
		url: mw.util.wikiScript(),
		data: {
			action: 'ajax',
			rs: 'wfCalendarLoadDay',
			rsargs: [ title, date, settings ]
		},
		dataType: 'html',
		success: function(result)
		{
			el.innerHTML = result;
		}
	});
};

// Hide hint
window.calendarhidedate = function(el)
{
	el.className = el.className.replace('ydate_shown', '');
	el.firstChild.style.display = "none";
};
