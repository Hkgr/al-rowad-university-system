# إثبات التصدير — بيانات اصطناعية فقط

ملفات PDF التالية ناتجة عن تنفيذ المكوّن الفعلي باستخدام بيانات Laravel الاصطناعية. صور PNG رُسمت من ملفات PDF نفسها بواسطة pdftoppm؛ لم تُستعمل الصورة المرجعية خلفيةً.

| الحالة | PDF | صورة |
| --- | --- | --- |
| كشف قصير، معدلات موزونة ومحاولة معادة ومستوى تاريخي | [short.pdf](short.pdf) | [الصفحة](short-1.png) |
| 6 سنوات، 18 فصلًا، 132 مقررًا وأسماء طويلة | [long.pdf — 8 صفحات](long.pdf) | [جميع الصفحات](long-contact-sheet.png) |
| أدلة ساعات مختلفة عن الأصل ورمز تاريخي | [historical.pdf](historical.pdf) | [الصفحة](historical-1.png) |
| F/Z/I/W وحقول شخصية ناقصة | [states-missing.pdf](states-missing.pdf) | [الصفحة](states-missing-1.png) |
| W فقط، دون معدل قابل للحساب | [withdrawal-only.pdf](withdrawal-only.pdf) | [الصفحة](withdrawal-only-1.png) |
| القالب الافتراضي للجهات الأخرى | [default-other-offices.pdf](default-other-offices.pdf) | [الأولى](default-other-offices-1.png) و[الثانية](default-other-offices-2.png) |
| بيانات حديثة وطلب اختياري مؤرخ يدويًا | [fresh-request.pdf](fresh-request.pdf) | [الصفحة](fresh-request-1.png) |

صور الواجهة المعروضة فعليًا ببيانات اصطناعية:

- [حوار التصدير — سطح المكتب](export-dialog-desktop.png)
- [حوار التصدير — الهاتف](export-dialog-mobile.png)
- [السجل على الهاتف](record-mobile.png)
- [صفحة السجل المرجعية](reference-record-desktop.png)
- [صفحة الاعتمادات المرجعية](reference-approvals-desktop.png)

browser-results.json يسجل عدد الصفحات والمقررات وحجم كل PDF وطلبات GET الاختبارية. ملفات layout.json تسجل قياسات كل صفحة والنصوص والخلايا التي تحقق منها المتصفح؛ أسماء المقررات والأشخاص والطلبات فيها اصطناعية.

تم فحص جميع صفحات الكشف الممتد في لوحة الصور، مع فتح صفحات PDF مفردة للتحقق من العربية والتفاصيل ورؤوس المتابعة والتواقيع. تثبت فحوص المتصفح عدم قص الخلايا، وعدم فقد أو تكرار أي مقرر، وظهور شعار فعلي، وترقيم الصفحات، وبقاء المعدل المفقود فارغًا، ومنع التصدير المكرر عند النقر مرتين.

راجع [العقد ومصادر البيانات ونتائج الاختبارات وحدود التحقق](../../exam-board-approved-transcript.md).
