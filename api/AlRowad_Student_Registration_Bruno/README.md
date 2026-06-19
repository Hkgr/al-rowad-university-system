# Al Rowad University - Student Registration APIs (Bruno)

## طريقة الاستخدام
1. افتح Bruno.
2. اختر Open Collection.
3. اختر مجلد هذه المجموعة.
4. اختر Environment باسم Local.
5. شغّل: 01 Auth / 01 Login.
6. انسخ التوكن من: data.token.
7. ضعه في متغير البيئة: token.
8. جرّب باقي الطلبات.

## ملاحظات
- Login ليس داخل api/v1:
  POST {{base_url}}/api/login

- باقي الطلبات:
  {{api_base_url}}/...

- كل الطلبات المحمية تحتاج:
  Authorization: Bearer {{token}}
