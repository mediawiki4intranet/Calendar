RELEASE=2.0.6
FILES=Makefile \
	Calendar.php CalendarAdjust.php ChangeLog.txt \
	calendar_template.html

release:
	/bin/rm -rf /tmp/Calendar
	mkdir /tmp/Calendar
	cp $(FILES) /tmp/Calendar
	(cd /tmp;tar cf Calendar.tar.gz Calendar)
	(cd /tmp;zip Calendar.zip Calendar/*)
	scp /tmp/Calendar.tar.gz /tmp/Calendar.zip  www.simson.net:simson.net/src/


