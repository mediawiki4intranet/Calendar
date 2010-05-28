function wikiaddevent(t,m,s) {
  var f = document.createElement('form');
  f.action = t;
  f.method = 'POST';
  var i = document.createElement('input');
  i.type = 'hidden';
  i.name = m;
  i.value = s;
  f.appendChild(i);
  document.body.appendChild(f);
  f.submit();
  return false;
}
