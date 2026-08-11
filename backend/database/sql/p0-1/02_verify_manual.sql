-- P0-1 MANUAL VERIFY: all categories contribute to OVERALL.
DROP TEMPORARY TABLE IF EXISTS p01_expected_chart;
CREATE TEMPORARY TABLE p01_expected_chart(unit_code VARCHAR(50) PRIMARY KEY,unit_name VARCHAR(255),type_code VARCHAR(50),parent_code VARCHAR(50) NULL);
INSERT INTO p01_expected_chart VALUES
('PRES','رئيس الجامعة','presidency',NULL),
('1','إدارة البحوث والدراسات','administration','PRES'),
('11','مركز البحوث والدراسات','center','1'),
('12','مجلة جامعة الرواد','unit','1'),
('13','المكتبة','center','1'),
('2','إدارة التطوير ودعم القرار','administration','PRES'),
('21','وحدة نظم المعلومات والتخطيط الاستراتيجي','unit','2'),
('22','مكتب الجودة والاعتماد الأكاديمي','office','2'),
('23','إدارة المشاريع الإنتاجية','administration','2'),
('3','إدارة التعليم الإلكتروني','administration','PRES'),
('31','التعليم عن بعد','unit','3'),
('32','التعليم الافتراضي','unit','3'),
('4','الأمين العام للجامعة','administration','PRES'),
('41','مكتب الشؤون القانونية','office','4'),
('42','مكتب الأمن والسلامة','office','4'),
('5','مديرية العلاقات العامة والإعلام','directorate','PRES'),
('51','مكتب العلاقات العامة','office','5'),
('52','مكتب الإعلام والاتصال','office','5'),
('6','وحدة التقييم والمتابعة','unit','PRES'),
('7','نائب رئيس الجامعة للشؤون الإدارية','vice_presidency','PRES'),
('71','مديرية الشؤون الإدارية','directorate','7'),
('711','مكتب الموارد البشرية','office','71'),
('712','مكتب الديوان والأرشيف','office','71'),
('713','مكتب الرعاية الصحية','office','71'),
('714','مكتب الخدمات الإدارية','office','71'),
('715','المكتب التقني','office','71'),
('72','مديرية الشؤون المالية','directorate','7'),
('721','مكتب المحاسبة','office','72'),
('722','أمين الصندوق','office','72'),
('723','أمين المستودع','office','72'),
('73','مديرية شؤون الطلاب','directorate','7'),
('731','مكتب الإرشاد والتوجيه','office','73'),
('732','مكتب القبول والتسجيل','office','73'),
('733','مكتب الخدمات الطلابية','office','73'),
('734','مكتب المنح والإيفاد والتبادل الطلابي','office','73'),
('735','إدارة الامتحانات','administration','73'),
('736','مكتب التوثيق والتصديق','office','73'),
('8','نائب رئيس الجامعة للشؤون العلمية','vice_presidency','PRES'),
('81','إدارة التعليم الجامعي','administration','8'),
('811','الكليات','college','81'),
('812','المعاهد','institute','81'),
('813','المخابر','lab','81'),
('82','إدارة الدراسات العليا والبحث العلمي','administration','8'),
('821','الماجستير','unit','82'),
('822','الدكتوراه','unit','82'),
('823','التعليم المهني','unit','82'),
('9','نائب رئيس الجامعة للشؤون المجتمعية','vice_presidency','PRES'),
('91','إدارة تنمية وبناء القدرات','administration','9'),
('911','مركز التأهيل والتدريب','center','91'),
('912','مركز اللغات الأجنبية','center','91'),
('913','مركز تقنية المعلومات','center','91'),
('914','مركز ريادة الأعمال','center','91'),
('92','إدارة الأنشطة المجتمعية','administration','9'),
('921','نادي الشباب والرياضة','club','92'),
('922','نادي التطوع والأنشطة المجتمعية','club','92'),
('923','نادي أصدقاء البيئة','club','92'),
('924','مكتب المسؤولية المجتمعية','office','92'),
('925','مكتب العدالة وحقوق الإنسان','office','92');
DROP TEMPORARY TABLE IF EXISTS p01_required_permissions;
CREATE TEMPORARY TABLE p01_required_permissions(role_code VARCHAR(80),permission_code VARCHAR(120),PRIMARY KEY(role_code,permission_code));
INSERT INTO p01_required_permissions VALUES
('board_member','students.view'),
('board_member','academic_structure.view'),
('board_member','courses.view'),
('board_member','registration.view'),
('board_member','exams.view'),
('board_member','exams.manage'),
('board_member','grades.view'),
('board_member','grades.manage'),
('board_member','system_settings.view'),
('registration_officer','students.view'),
('registration_officer','students.manage'),
('registration_officer','admissions.view'),
('registration_officer','admissions.manage'),
('registration_officer','academic_structure.view'),
('registration_officer','courses.view'),
('registration_officer','registration.view'),
('registration_officer','registration.manage'),
('registration_officer','system_settings.view'),
('doctor_instructor','courses.view'),
('doctor_instructor','students.view'),
('doctor_instructor','attendance.view'),
('doctor_instructor','attendance.manage'),
('doctor_instructor','grades.view'),
('doctor_instructor','grades.manage'),
('student','students.view'),
('student','registration.view'),
('student','grades.view'),
('student','attendance.view');
SELECT 'organizational_units_exact_58' check_name,IF((SELECT COUNT(*) FROM p01_expected_chart)=58 AND (SELECT COUNT(*) FROM organizational_units u JOIN p01_expected_chart e ON e.unit_code=u.unit_code)=58,'PASS','FAIL') status;
SELECT 'organizational_names_types_parents' check_name,IF(NOT EXISTS(SELECT 1 FROM p01_expected_chart e LEFT JOIN organizational_units u ON u.unit_code=e.unit_code AND u.unit_name=e.unit_name AND u.is_active=1 LEFT JOIN organizational_unit_types t ON t.unit_type_id=u.unit_type_id AND t.type_code=e.type_code LEFT JOIN organizational_units p ON p.organizational_unit_id=u.parent_unit_id WHERE u.organizational_unit_id IS NULL OR t.unit_type_id IS NULL OR NOT (p.unit_code<=>e.parent_code)),'PASS','FAIL') status;
SELECT 'employee_creation_and_user_links' check_name,IF((SELECT COUNT(*) FROM users u JOIN employees emp ON emp.employee_id=u.employee_id JOIN organizational_units ou ON ou.organizational_unit_id=emp.organizational_unit_id WHERE (u.username='registrar' AND ou.unit_code='732') OR (u.username='exam.board' AND ou.unit_code='735'))=2,'PASS','FAIL') status;
SELECT 'required_role_permissions' check_name,IF(NOT EXISTS(SELECT 1 FROM p01_required_permissions e LEFT JOIN roles r ON r.role_code=e.role_code LEFT JOIN permissions p ON p.permission_code=e.permission_code LEFT JOIN role_permissions rp ON rp.role_id=r.role_id AND rp.permission_id=p.permission_id WHERE rp.role_permission_id IS NULL),'PASS','FAIL') status;
SELECT 'user_access_scopes' check_name,IF((SELECT COUNT(*) FROM user_access_scopes WHERE is_active=1)>=2,'PASS','FAIL') status;
SELECT 'staff_university_scopes' check_name,IF((SELECT COUNT(DISTINCT u.user_id) FROM users u JOIN user_access_scopes s ON s.user_id=u.user_id AND s.scope_type='university' AND s.is_active=1 JOIN organizational_units p ON p.organizational_unit_id=s.scope_id AND p.unit_code='PRES' WHERE u.username IN ('registrar','exam.board'))=2,'PASS','FAIL') status;
SELECT 'orphan_scopes' check_name,IF(NOT EXISTS(SELECT 1 FROM user_access_scopes s LEFT JOIN organizational_units root ON s.scope_type='university' AND root.organizational_unit_id=s.scope_id AND root.unit_code='PRES' LEFT JOIN colleges c ON s.scope_type='college' AND c.college_id=s.scope_id LEFT JOIN departments d ON s.scope_type='department' AND d.department_id=s.scope_id LEFT JOIN academic_programs ap ON s.scope_type='program' AND ap.academic_program_id=s.scope_id LEFT JOIN course_offerings co ON s.scope_type='section' AND co.course_offering_id=s.scope_id WHERE CASE s.scope_type WHEN 'university' THEN root.organizational_unit_id WHEN 'college' THEN c.college_id WHEN 'department' THEN d.department_id WHEN 'program' THEN ap.academic_program_id WHEN 'section' THEN co.course_offering_id END IS NULL),'PASS','FAIL') status;
SELECT 'duplicate_identity_links' check_name,IF(NOT EXISTS(SELECT student_id FROM users WHERE student_id IS NOT NULL GROUP BY student_id HAVING COUNT(*)>1) AND NOT EXISTS(SELECT employee_id FROM users WHERE employee_id IS NOT NULL GROUP BY employee_id HAVING COUNT(*)>1),'PASS','FAIL') status;
SELECT 'required_p01_test_identities' check_name,IF((SELECT COUNT(*) FROM users WHERE username IN ('registrar','exam.board'))=2 AND (SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id=u.user_id AND ur.is_active=1 JOIN roles r ON r.role_id=ur.role_id WHERE (u.username='registrar' AND r.role_code='registration_officer') OR (u.username='exam.board' AND r.role_code='board_member'))=2,'PASS','FAIL') status;
SELECT 'OVERALL' check_name,IF((SELECT COUNT(*) FROM p01_expected_chart)=58 AND NOT EXISTS(SELECT 1 FROM p01_expected_chart e LEFT JOIN organizational_units u ON u.unit_code=e.unit_code AND u.unit_name=e.unit_name AND u.is_active=1 LEFT JOIN organizational_unit_types t ON t.unit_type_id=u.unit_type_id AND t.type_code=e.type_code LEFT JOIN organizational_units p ON p.organizational_unit_id=u.parent_unit_id WHERE u.organizational_unit_id IS NULL OR t.unit_type_id IS NULL OR NOT (p.unit_code<=>e.parent_code)) AND (SELECT COUNT(*) FROM users u JOIN employees emp ON emp.employee_id=u.employee_id JOIN organizational_units ou ON ou.organizational_unit_id=emp.organizational_unit_id WHERE (u.username='registrar' AND ou.unit_code='732') OR (u.username='exam.board' AND ou.unit_code='735'))=2 AND NOT EXISTS(SELECT 1 FROM p01_required_permissions e LEFT JOIN roles r ON r.role_code=e.role_code LEFT JOIN permissions p ON p.permission_code=e.permission_code LEFT JOIN role_permissions rp ON rp.role_id=r.role_id AND rp.permission_id=p.permission_id WHERE rp.role_permission_id IS NULL) AND (SELECT COUNT(DISTINCT u.user_id) FROM users u JOIN user_access_scopes s ON s.user_id=u.user_id AND s.scope_type='university' AND s.is_active=1 JOIN organizational_units p ON p.organizational_unit_id=s.scope_id AND p.unit_code='PRES' WHERE u.username IN ('registrar','exam.board'))=2 AND NOT EXISTS(SELECT 1 FROM user_access_scopes s LEFT JOIN organizational_units root ON s.scope_type='university' AND root.organizational_unit_id=s.scope_id AND root.unit_code='PRES' LEFT JOIN colleges c ON s.scope_type='college' AND c.college_id=s.scope_id LEFT JOIN departments d ON s.scope_type='department' AND d.department_id=s.scope_id LEFT JOIN academic_programs ap ON s.scope_type='program' AND ap.academic_program_id=s.scope_id LEFT JOIN course_offerings co ON s.scope_type='section' AND co.course_offering_id=s.scope_id WHERE CASE s.scope_type WHEN 'university' THEN root.organizational_unit_id WHEN 'college' THEN c.college_id WHEN 'department' THEN d.department_id WHEN 'program' THEN ap.academic_program_id WHEN 'section' THEN co.course_offering_id END IS NULL) AND NOT EXISTS(SELECT student_id FROM users WHERE student_id IS NOT NULL GROUP BY student_id HAVING COUNT(*)>1) AND NOT EXISTS(SELECT employee_id FROM users WHERE employee_id IS NOT NULL GROUP BY employee_id HAVING COUNT(*)>1) AND (SELECT COUNT(*) FROM users WHERE username IN ('registrar','exam.board'))=2 AND (SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id=u.user_id AND ur.is_active=1 JOIN roles r ON r.role_id=ur.role_id WHERE (u.username='registrar' AND r.role_code='registration_officer') OR (u.username='exam.board' AND r.role_code='board_member'))=2,'PASS','FAIL') status;
DROP TEMPORARY TABLE p01_required_permissions;
DROP TEMPORARY TABLE p01_expected_chart;
