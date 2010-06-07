function wikiaddevent(t,m,s)
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
    i.value = 'ぺ';
    f.appendChild(i);
  }
  document.body.appendChild(f);
  f.submit();
  return false;
}
function calendarshowdate(el,title,date)
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
  var f = function (request)
  {
    if (request.status != 200)
      return;
    el.innerHTML = request.responseText;
  };
  sajax_do_call('wfCalendarLoadDay', [title, date, settings], f);
}
function calendarhidedate(el)
{
  el.className = el.className.replace('ydate_shown', '');
  el.firstChild.style.display = "none";
}
