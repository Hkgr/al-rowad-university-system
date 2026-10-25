-- MariaDB / phpMyAdmin. Maintenance required: DDL IMPLICITLY COMMITS; this is NOT a transactional migration.
-- All guards are inspected before any ALTER. Only these two columns and the named CHECK may change.
USE alrowad_uni_rust;
DELIMITER //
BEGIN NOT ATOMIC
DECLARE issue_count INT DEFAULT 0;
WITH expected_guards AS (
SELECT 'TRIGGER' kind,'sc_plan_progression_decisions_i' object_name,'student_progression_decisions' target_table,'BEFORE' timing,'INSERT' operation,'b6498e918728079190b5477170cbdb6aec8959cc32d974e62a31876e6feae6d9' body_hash
UNION ALL SELECT 'FUNCTION','sc_catalog_membership_program','','','','7b4a1107254e7ea26c0215265bc6c885198233a5f5d9c0e47b6a7bccd87057e9'
UNION ALL SELECT 'TRIGGER','sc_cat_courses_d','courses','BEFORE','DELETE','f66b756c6804c07484407650997cadc9dc94a7a01e1a252c3a9746c31111a379'
UNION ALL SELECT 'TRIGGER','sc_plan_requests_i','student_registration_requests','BEFORE','INSERT','b6498e918728079190b5477170cbdb6aec8959cc32d974e62a31876e6feae6d9'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_supplementary_exam_materializations_u','supplementary_exam_materializations','BEFORE','UPDATE','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'PROCEDURE','sc_catalog_touch','','','','bfd88ee209c9df4486ddf6b7f5c8a6655608053a62d43c912e225aebc086e670'
UNION ALL SELECT 'PROCEDURE','sc_plan_editable','','','','dc09d342d965d36a67288729c98088b02fd767f71cf18c2d2d701db91b85be21'
UNION ALL SELECT 'TRIGGER','sc_use_admission_applications_d','admission_applications','BEFORE','DELETE','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_modification_items_u','student_registration_modification_items','BEFORE','UPDATE','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_use_ministry_placement_records_i','ministry_placement_records','BEFORE','INSERT','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_appeal_statuses_i','appeal_statuses','BEFORE','INSERT','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_request_items_u','student_registration_request_items','BEFORE','UPDATE','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_plan_course_registrations_i','student_course_registrations','BEFORE','INSERT','e66f408cc0045421a266fbc4e5a684ca48a3875c46c1910a3a4bbd569ea2383c'
UNION ALL SELECT 'TRIGGER','sc_plan_program_u','academic_programs','BEFORE','UPDATE','622543b68ca7d04890421dd0192fef2548ecc7ab9c08ed99458e60f42cb6c774'
UNION ALL SELECT 'TRIGGER','sc_use_course_offerings_u','course_offerings','BEFORE','UPDATE','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_ref_academic_levels_i','academic_levels','BEFORE','INSERT','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_ref_semesters_d','semesters','BEFORE','DELETE','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_use_supplementary_exam_offerings_i','supplementary_exam_offerings','BEFORE','INSERT','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_cat_program_course_requirement_groups_d','program_course_requirement_groups','BEFORE','DELETE','121fb2d5c4314fd6b5784c732212bb2676e9d1496476ccb7c84727edc649a60d'
UNION ALL SELECT 'TRIGGER','sc_plan_versions_i','academic_plan_versions','BEFORE','INSERT','7d07d1260d43f263ba47fb273c9bbf88d120825bbf98acf45ed582daf89317d9'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_supplementary_exam_registrations_u','supplementary_exam_registrations','BEFORE','UPDATE','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_use_student_graduation_decisions_d','student_graduation_decisions','BEFORE','DELETE','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_ref_departments_d','departments','BEFORE','DELETE','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_use_students_d','students','BEFORE','DELETE','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_ref_colleges_u','colleges','BEFORE','UPDATE','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_use_supplementary_exam_offerings_d','supplementary_exam_offerings','BEFORE','DELETE','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_supplementary_exam_registrations_i','supplementary_exam_registrations','BEFORE','INSERT','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_plan_student_assign','students','AFTER','INSERT','4867bb85bc9a91c1198398648d1d6fd1aeaa6e54103c1c65766fc8d3aee3737b'
UNION ALL SELECT 'TRIGGER','sc_use_admission_applications_i','admission_applications','BEFORE','INSERT','8ba68215d8ba14df3bbb7ca9c336bf65adfb3b2be21366b9a2e1e03bae5d5a12'
UNION ALL SELECT 'TRIGGER','sc_plan_assignment_i','student_academic_plan_assignments','BEFORE','INSERT','1dd3f00b0e9f7e94fd76020b475940b7a4c9dc96b1b01f573889203e2061ae37'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_withdrawal_requests_d','student_registration_withdrawal_requests','BEFORE','DELETE','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'FUNCTION','sc_catalog_course_used','','','','45c252ed53c325d93d5ec2e94355c398b449a32f895204915bd563cc8b2b7754'
UNION ALL SELECT 'TRIGGER','sc_plan_events_i','academic_plan_events','BEFORE','INSERT','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_cat_academic_programs_i','academic_programs','BEFORE','INSERT','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_grade_audit_logs_u','grade_audit_logs','BEFORE','UPDATE','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_plan_events_d','academic_plan_events','BEFORE','DELETE','675d37be61a7500f1698a04dad0135baa3312a9cf0a9e1bbb3956b93a60b35f0'
UNION ALL SELECT 'TRIGGER','sc_plan_requests_u','student_registration_requests','BEFORE','UPDATE','a4db76e29955c8b419d36600b3e00d4f94a4cca4b33e4208b51a2d7d49e4fefd'
UNION ALL SELECT 'TRIGGER','sc_use_student_progression_decisions_d','student_progression_decisions','BEFORE','DELETE','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_plan_replacement_requests_u','student_registration_replacement_requests','BEFORE','UPDATE','a4db76e29955c8b419d36600b3e00d4f94a4cca4b33e4208b51a2d7d49e4fefd'
UNION ALL SELECT 'TRIGGER','sc_ref_colleges_d','colleges','BEFORE','DELETE','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_use_course_offerings_d','course_offerings','BEFORE','DELETE','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_plan_assignment_u','student_academic_plan_assignments','BEFORE','UPDATE','4a41dcb3f5a7164cb83d1074aa5e074622614243ad6bef4883190a6042912135'
UNION ALL SELECT 'TRIGGER','sc_plan_prerequisite_u','course_prerequisites','BEFORE','UPDATE','e4927cf6539f6e2da00d6c213cf30be44a5ab60d4f82682810501ab17a358abe'
UNION ALL SELECT 'TRIGGER','sc_ref_semesters_i','semesters','BEFORE','INSERT','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_withdrawal_requests_i','student_registration_withdrawal_requests','BEFORE','INSERT','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_cat_course_prerequisites_i','course_prerequisites','BEFORE','INSERT','7b157260d60972e93cc4b7541cb9b938a74a6cf75ea30952a628b96d310dda61'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_supplementary_exam_materializations_d','supplementary_exam_materializations','BEFORE','DELETE','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_ref_result_statuses_i','result_statuses','BEFORE','INSERT','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_plan_modification_requests_i','student_registration_modification_requests','BEFORE','INSERT','b6498e918728079190b5477170cbdb6aec8959cc32d974e62a31876e6feae6d9'
UNION ALL SELECT 'TRIGGER','sc_cat_program_courses_d','program_courses','BEFORE','DELETE','d89895b4ccca8588688a85cda8d12b02a3c81ce01d1874fcde3bc228eef3fb4a'
UNION ALL SELECT 'TRIGGER','sc_use_student_progression_decisions_i','student_progression_decisions','BEFORE','INSERT','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_cat_academic_requirement_groups_u','academic_requirement_groups','BEFORE','UPDATE','a76107419f6c2b389dd84eb0d544c84e319f8693b55e5ec950fdc53b8bb42d99'
UNION ALL SELECT 'TRIGGER','sc_ref_departments_i','departments','BEFORE','INSERT','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_plan_graduation_decisions_u','student_graduation_decisions','BEFORE','UPDATE','a4db76e29955c8b419d36600b3e00d4f94a4cca4b33e4208b51a2d7d49e4fefd'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_course_results_i','student_course_results','BEFORE','INSERT','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_ref_result_statuses_d','result_statuses','BEFORE','DELETE','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_request_items_d','student_registration_request_items','BEFORE','DELETE','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_use_ministry_placement_records_u','ministry_placement_records','BEFORE','UPDATE','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_appeal_statuses_d','appeal_statuses','BEFORE','DELETE','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_cat_academic_programs_u','academic_programs','BEFORE','UPDATE','121ea3e0929007b0e839ff74750b81c141a1ff0320f1045b3f3f766cb2f87559'
UNION ALL SELECT 'TRIGGER','sc_plan_prerequisite_i','course_prerequisites','BEFORE','INSERT','24f31a914465d5faad4c79ea834551eb872d95515513c5510d8c8ee5b8ede66b'
UNION ALL SELECT 'FUNCTION','sc_completion_code_allowed','','','','494f78ff73ce1304919301b59965e63b6cbff71594133845a5527d1afbb5ec47'
UNION ALL SELECT 'TRIGGER','sc_ref_colleges_i','colleges','BEFORE','INSERT','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_grade_appeals_d','grade_appeals','BEFORE','DELETE','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_modification_items_i','student_registration_modification_items','BEFORE','INSERT','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_grade_audit_logs_i','grade_audit_logs','BEFORE','INSERT','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_replacement_items_d','student_registration_replacement_items','BEFORE','DELETE','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_cat_program_course_requirement_groups_i','program_course_requirement_groups','BEFORE','INSERT','77b05454a0c6c01a8ac93303fcb9a5dbcd649527e42a81c022e5a613861cdcbd'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_withdrawal_requests_u','student_registration_withdrawal_requests','BEFORE','UPDATE','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_plan_modification_requests_u','student_registration_modification_requests','BEFORE','UPDATE','a4db76e29955c8b419d36600b3e00d4f94a4cca4b33e4208b51a2d7d49e4fefd'
UNION ALL SELECT 'TRIGGER','sc_plan_assignment_d','student_academic_plan_assignments','BEFORE','DELETE','db7d7139638dbcfd49219ce372beac74aa49712c93c58e0097f0d506eac94948'
UNION ALL SELECT 'TRIGGER','sc_use_student_graduation_decisions_u','student_graduation_decisions','BEFORE','UPDATE','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_course_results_d','student_course_results','BEFORE','DELETE','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_cat_courses_u','courses','BEFORE','UPDATE','eda7b0351908d1f97e7084445e3a250074d4ae756e58a52429f7bcb1f2c565ad'
UNION ALL SELECT 'TRIGGER','sc_cat_academic_requirement_groups_d','academic_requirement_groups','BEFORE','DELETE','d89895b4ccca8588688a85cda8d12b02a3c81ce01d1874fcde3bc228eef3fb4a'
UNION ALL SELECT 'TRIGGER','sc_use_student_progression_decisions_u','student_progression_decisions','BEFORE','UPDATE','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_plan_prerequisite_d','course_prerequisites','BEFORE','DELETE','b938230a3f85f11bff6b9f065295edf7f53b31630aa602f97f0b5a73fd0afb2e'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_appeal_statuses_u','appeal_statuses','BEFORE','UPDATE','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_plan_course_registrations_u','student_course_registrations','BEFORE','UPDATE','62c93036411edae7570b8c1655d8bacc33c424dd0b81e285f1ed5d32419860c8'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_grade_appeals_i','grade_appeals','BEFORE','INSERT','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'FUNCTION','sc_catalog_program_used','','','','7ec77788702707d3a49c1489c4f5ad9b2951fa7d0207d2a32b93df71daa7c192'
UNION ALL SELECT 'TRIGGER','sc_ref_result_statuses_u','result_statuses','BEFORE','UPDATE','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_plan_program_i','academic_programs','BEFORE','INSERT','497b9da8122a7ab2f6ddf503f4b20144fc2774c3427ff3eebc37bbf1ebb9f154'
UNION ALL SELECT 'TRIGGER','sc_plan_graduation_decisions_i','student_graduation_decisions','BEFORE','INSERT','b6498e918728079190b5477170cbdb6aec8959cc32d974e62a31876e6feae6d9'
UNION ALL SELECT 'TRIGGER','sc_ref_academic_levels_u','academic_levels','BEFORE','UPDATE','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_ref_departments_u','departments','BEFORE','UPDATE','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_grade_approvals_i','grade_approvals','BEFORE','INSERT','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_supplementary_exam_materializations_i','supplementary_exam_materializations','BEFORE','INSERT','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'PROCEDURE','sc_plan_mapping_editable','','','','4344e6ebef78330ddb144f69676d4059e3d4d5ae5784db28f2a39b166bb60e55'
UNION ALL SELECT 'TRIGGER','sc_use_student_graduation_decisions_i','student_graduation_decisions','BEFORE','INSERT','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_use_ministry_placement_records_d','ministry_placement_records','BEFORE','DELETE','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_cat_academic_programs_d','academic_programs','BEFORE','DELETE','bd8ea0d5be142713757e3b6068c03a27a1577fb6201779568bd5ea87f0f25f40'
UNION ALL SELECT 'TRIGGER','sc_cat_course_departments_u','course_departments','BEFORE','UPDATE','b6ba3b2e31bd1de708114b5c37ca081ac2693edf0ae7bae99454beaee4bf4e33'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_modification_items_d','student_registration_modification_items','BEFORE','DELETE','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_grade_approvals_u','grade_approvals','BEFORE','UPDATE','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_use_course_offerings_i','course_offerings','BEFORE','INSERT','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_cat_program_course_requirement_groups_u','program_course_requirement_groups','BEFORE','UPDATE','98ac521ae317fa05714f7832419eddc8fef27fa1be8aa346d9dacd16231e732a'
UNION ALL SELECT 'TRIGGER','sc_plan_course_u','courses','BEFORE','UPDATE','339eaddd7c35dfbcab560b7935598eed9b14a9524916e417b21f001e5ba0b2bb'
UNION ALL SELECT 'TRIGGER','sc_cat_course_departments_i','course_departments','BEFORE','INSERT','7b157260d60972e93cc4b7541cb9b938a74a6cf75ea30952a628b96d310dda61'
UNION ALL SELECT 'TRIGGER','sc_plan_replacement_requests_i','student_registration_replacement_requests','BEFORE','INSERT','b6498e918728079190b5477170cbdb6aec8959cc32d974e62a31876e6feae6d9'
UNION ALL SELECT 'TRIGGER','sc_cat_program_courses_i','program_courses','BEFORE','INSERT','823ef2cadad64296a3b380ade0d4504359d905814fac300d5e1acfe3e6af921c'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_grade_appeals_u','grade_appeals','BEFORE','UPDATE','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_use_admission_applications_u','admission_applications','BEFORE','UPDATE','8a8332a205fbd8dda56c8f56151bce90b392be796c9d8461221fe105ca78f1d7'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_request_items_i','student_registration_request_items','BEFORE','INSERT','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_plan_versions_d','academic_plan_versions','BEFORE','DELETE','40493ff5fed6818958002718af4dfcc430383bc11c92344ce722976cce7127a2'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_replacement_items_u','student_registration_replacement_items','BEFORE','UPDATE','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_cat_course_departments_d','course_departments','BEFORE','DELETE','f66b756c6804c07484407650997cadc9dc94a7a01e1a252c3a9746c31111a379'
UNION ALL SELECT 'TRIGGER','sc_ref_semesters_u','semesters','BEFORE','UPDATE','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_cat_course_prerequisites_u','course_prerequisites','BEFORE','UPDATE','e6b4a0255345ed11c8f5df1ae8e1230931084d46741bd8e771681dfdb24c7fdf'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_grade_approvals_d','grade_approvals','BEFORE','DELETE','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_ref_academic_levels_d','academic_levels','BEFORE','DELETE','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_use_students_i','students','BEFORE','INSERT','8ba68215d8ba14df3bbb7ca9c336bf65adfb3b2be21366b9a2e1e03bae5d5a12'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_course_results_u','student_course_results','BEFORE','UPDATE','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'PROCEDURE','sc_plan_accepting','','','','5c992334f981fdf2393e238d46d878c9a26af18edcf3f133c07e2d8b2d96e62e'
UNION ALL SELECT 'TRIGGER','sc_plan_events_u','academic_plan_events','BEFORE','UPDATE','675d37be61a7500f1698a04dad0135baa3312a9cf0a9e1bbb3956b93a60b35f0'
UNION ALL SELECT 'TRIGGER','sc_cat_program_courses_u','program_courses','BEFORE','UPDATE','f5024a8f2535c6f98811838bd5793b5821e6b1a9030158b826b216ed116a7421'
UNION ALL SELECT 'TRIGGER','sc_plan_versions_u','academic_plan_versions','BEFORE','UPDATE','91a47237d5ac01d514b35e4037e51c05ed705bc4a2280c677056aa2b05cd46ef'
UNION ALL SELECT 'FUNCTION','sc_plan_fixed_course','','','','cddfc91b2060d02551b07b245718992a627543483aabfac7f016e75e2f2c1d29'
UNION ALL SELECT 'FUNCTION','sc_plan_student_version','','','','1a78e08d7a2f529dc9183b9cf9679d288b858096722b4512d6de9f66242e4677'
UNION ALL SELECT 'TRIGGER','sc_cat_academic_requirement_groups_i','academic_requirement_groups','BEFORE','INSERT','823ef2cadad64296a3b380ade0d4504359d905814fac300d5e1acfe3e6af921c'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_replacement_items_i','student_registration_replacement_items','BEFORE','INSERT','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'PROCEDURE','sc_plan_touch','','','','6e24c728cc4bfeddaad3bb5bdc0ad333876709970754a72126c03df14c1a4baa'
UNION ALL SELECT 'TRIGGER','sc_cat_courses_i','courses','BEFORE','INSERT','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_supplementary_exam_registrations_d','supplementary_exam_registrations','BEFORE','DELETE','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
UNION ALL SELECT 'TRIGGER','sc_use_students_u','students','BEFORE','UPDATE','e6fae8c3770994daca86791a43535e7c7622046f9f766d13067b0aaef6cad57c'
UNION ALL SELECT 'TRIGGER','sc_cat_course_prerequisites_d','course_prerequisites','BEFORE','DELETE','f66b756c6804c07484407650997cadc9dc94a7a01e1a252c3a9746c31111a379'
UNION ALL SELECT 'TRIGGER','sc_plan_progression_decisions_u','student_progression_decisions','BEFORE','UPDATE','a4db76e29955c8b419d36600b3e00d4f94a4cca4b33e4208b51a2d7d49e4fefd'
UNION ALL SELECT 'TRIGGER','sc_use_supplementary_exam_offerings_u','supplementary_exam_offerings','BEFORE','UPDATE','34951c086baf310667e43009618265a2f5fb3e98fe96f8b146f812c64698160b'
UNION ALL SELECT 'TRIGGER','sc_plan_epoch_grade_audit_logs_d','grade_audit_logs','BEFORE','DELETE','1d700756a2512c36b4de3855db397000d99c61c4657beac259e320ac748fe102'
), expected_parameters AS (
SELECT 'sc_catalog_program_used' routine_name,0 position,NULL parameter_name,'tinyint' parameter_type,NULL capacity,NULL mode
UNION ALL SELECT 'sc_catalog_program_used',1,'program_key','int',NULL,'IN'
UNION ALL SELECT 'sc_catalog_membership_program',0,NULL,'int',NULL,NULL
UNION ALL SELECT 'sc_catalog_membership_program',1,'membership_key','int',NULL,'IN'
UNION ALL SELECT 'sc_catalog_course_used',0,NULL,'tinyint',NULL,NULL
UNION ALL SELECT 'sc_catalog_course_used',1,'course_key','int',NULL,'IN'
UNION ALL SELECT 'sc_plan_editable',1,'program_key','int',NULL,'IN'
UNION ALL SELECT 'sc_plan_editable',2,'version_key','int',NULL,'IN'
UNION ALL SELECT 'sc_plan_fixed_course',0,NULL,'tinyint',NULL,NULL
UNION ALL SELECT 'sc_plan_fixed_course',1,'course_key','int',NULL,'IN'
UNION ALL SELECT 'sc_plan_mapping_editable',1,'membership_key','int',NULL,'IN'
UNION ALL SELECT 'sc_plan_mapping_editable',2,'group_key','int',NULL,'IN'
UNION ALL SELECT 'sc_plan_accepting',1,'program_key','int',NULL,'IN'
UNION ALL SELECT 'sc_plan_student_version',0,NULL,'int',NULL,NULL
UNION ALL SELECT 'sc_plan_student_version',1,'student_key','int',NULL,'IN'
UNION ALL SELECT 'sc_completion_code_allowed',0,NULL,'tinyint',NULL,NULL
UNION ALL SELECT 'sc_completion_code_allowed',1,'course_key','int',NULL,'IN'
UNION ALL SELECT 'sc_completion_code_allowed',2,'previous_code','varchar',50,'IN'
UNION ALL SELECT 'sc_completion_code_allowed',3,'next_code','varchar',50,'IN'
), actual_parameters AS (
SELECT specific_name routine_name,ordinal_position position,parameter_name,data_type parameter_type,character_maximum_length capacity,parameter_mode mode
FROM information_schema.parameters WHERE specific_schema=DATABASE()
), actual_guards AS (
SELECT 'TRIGGER' kind,trigger_name object_name,event_object_table target_table,action_timing timing,event_manipulation operation,
SHA2(REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' '),256) body_hash FROM information_schema.triggers WHERE trigger_schema=DATABASE()
UNION ALL SELECT routine_type,routine_name,'','','',SHA2(REGEXP_REPLACE(TRIM(routine_definition),'[[:space:]]+',' '),256)
FROM information_schema.routines WHERE routine_schema=DATABASE()
), actual_fk AS (
SELECT k.constraint_name,GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position) source_columns,
k.referenced_table_schema target_schema,k.referenced_table_name target_table,
GROUP_CONCAT(k.referenced_column_name ORDER BY k.ordinal_position) target_columns,r.update_rule,r.delete_rule
FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r
ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name AND r.table_name=k.table_name
WHERE k.table_schema=DATABASE() AND k.table_name='program_courses' AND k.referenced_table_name IS NOT NULL
GROUP BY k.constraint_name,k.referenced_table_schema,k.referenced_table_name,r.update_rule,r.delete_rule
), expected_fk AS (
SELECT 'academic_program_id' source_columns,'academic_programs' target_table,'academic_program_id' target_columns
UNION ALL SELECT 'course_id','courses','course_id'
UNION ALL SELECT 'academic_level_id','academic_levels','academic_level_id'
UNION ALL SELECT 'recommended_semester_id','semesters','semester_id'
UNION ALL SELECT 'academic_plan_version_id,academic_program_id','academic_plan_versions','academic_plan_version_id,academic_program_id'
), issues AS (
SELECT 'database_or_engine' issue WHERE DATABASE()<>'alrowad_uni_rust' OR VERSION() NOT LIKE '%MariaDB%'
 OR NOT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='program_courses' AND engine='InnoDB' AND table_type='BASE TABLE')
UNION ALL SELECT 'installed_catalog_plan_controls' WHERE
NOT EXISTS(SELECT 1 FROM academic_catalog_control WHERE control_id=1 AND schema_version=1 AND is_ready=1)
OR NOT EXISTS(SELECT 1 FROM academic_plan_control WHERE control_id=1 AND schema_version=1 AND is_ready=1)
UNION ALL SELECT 'placement_column_definition' WHERE
(SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='program_courses'
 AND column_name IN('academic_level_id','recommended_semester_id') AND column_type='int(11)' AND is_nullable IN('YES','NO')
 AND (column_default IS NULL OR column_default='NULL') AND column_comment='' AND extra='' AND COALESCE(generation_expression,'')='')<>2
UNION ALL SELECT 'membership_identity_definition' WHERE
(SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='program_courses'
 AND column_name IN('program_course_id','academic_program_id','course_id') AND column_type='int(11)' AND is_nullable='NO')<>3
 OR NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='program_courses' AND column_name='course_type'
 AND data_type='varchar' AND character_maximum_length>=50 AND is_nullable='NO')
UNION ALL SELECT 'generated_plan_context' WHERE NOT EXISTS(
SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='program_courses' AND column_name='plan_scope_key'
 AND column_type='int(11)' AND extra LIKE '%STORED GENERATED%' AND REGEXP_REPLACE(LOWER(generation_expression),'[[:space:]]+','')='coalesce(`academic_plan_version_id`,0)')
UNION ALL SELECT 'versioned_unique_index' WHERE NOT EXISTS(
 SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='program_courses' AND index_name='uq_program_plan_course' AND non_unique=0
 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index)='academic_program_id,plan_scope_key,course_id')
 OR NOT EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='program_courses' AND index_name='PRIMARY'
 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index)='program_course_id')
 OR EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='program_courses' AND non_unique=0 AND index_name NOT IN('PRIMARY','uq_program_plan_course'))
UNION ALL SELECT 'foreign_key_mapping_or_rules' WHERE (SELECT COUNT(*) FROM actual_fk)<>5 OR EXISTS(
 SELECT 1 FROM expected_fk e LEFT JOIN actual_fk a ON a.source_columns=e.source_columns AND a.target_table=e.target_table
 AND a.target_columns=e.target_columns AND a.target_schema=DATABASE() AND a.update_rule IN('RESTRICT','NO ACTION') AND a.delete_rule IN('RESTRICT','NO ACTION')
 WHERE a.constraint_name IS NULL)
UNION ALL SELECT 'unknown_check_definition' WHERE EXISTS(
 SELECT 1 FROM information_schema.check_constraints WHERE constraint_schema=DATABASE() AND table_name='program_courses'
 AND (constraint_name<>'chk_program_course_advisory_placement'
 OR REPLACE(REGEXP_REPLACE(LOWER(check_clause),'[[:space:]]+',''),'`','')<>'casewhenacademic_level_idisnullorrecommended_semester_idisnullthencourse_type=''elective''andacademic_level_idisnullandrecommended_semester_idisnullelse1end'))
UNION ALL SELECT CONCAT('guard:',e.object_name) FROM expected_guards e LEFT JOIN actual_guards a
 ON a.kind=e.kind AND a.object_name=e.object_name AND a.target_table=e.target_table AND a.timing=e.timing AND a.operation=e.operation AND a.body_hash=e.body_hash
 WHERE a.object_name IS NULL
UNION ALL SELECT CONCAT('routine_signature:',e.object_name) FROM expected_guards e WHERE e.kind<>'TRIGGER' AND (
(SELECT COUNT(*) FROM actual_parameters a WHERE a.routine_name=e.object_name)<>(SELECT COUNT(*) FROM expected_parameters p WHERE p.routine_name=e.object_name)
OR EXISTS(SELECT 1 FROM expected_parameters p WHERE p.routine_name=e.object_name AND NOT EXISTS(
SELECT 1 FROM actual_parameters a WHERE a.routine_name=p.routine_name AND a.position=p.position AND (a.parameter_name<=>p.parameter_name)
AND a.parameter_type=p.parameter_type AND (a.capacity<=>p.capacity) AND (a.mode<=>p.mode))))
UNION ALL SELECT CONCAT('unknown_trigger:',a.object_name) FROM actual_guards a WHERE a.kind='TRIGGER'
 AND (a.target_table='program_courses' OR a.object_name LIKE 'sc_cat_%' OR a.object_name LIKE 'sc_plan_%')
 AND NOT EXISTS(SELECT 1 FROM expected_guards e WHERE e.kind=a.kind AND e.object_name=a.object_name)
UNION ALL SELECT 'invalid_existing_placement' WHERE EXISTS(
 SELECT 1 FROM program_courses WHERE NOT (CASE WHEN academic_level_id IS NULL OR recommended_semester_id IS NULL THEN course_type='elective' AND academic_level_id IS NULL AND recommended_semester_id IS NULL ELSE 1 END))
UNION ALL SELECT 'constraint_checks_disabled' WHERE @@check_constraint_checks<>1
)
SELECT COUNT(*) INTO issue_count FROM issues;
IF issue_count<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='elective_placement_incompatible_schema_or_data'; END IF;

IF NOT EXISTS(SELECT 1 FROM information_schema.check_constraints WHERE constraint_schema=DATABASE() AND table_name='program_courses' AND constraint_name='chk_program_course_advisory_placement') THEN
 ALTER TABLE program_courses ADD CONSTRAINT chk_program_course_advisory_placement CHECK (CASE WHEN academic_level_id IS NULL OR recommended_semester_id IS NULL THEN course_type='elective' AND academic_level_id IS NULL AND recommended_semester_id IS NULL ELSE 1 END);
END IF;
IF (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='program_courses' AND column_name IN('academic_level_id','recommended_semester_id') AND is_nullable='YES')<>2 THEN
 ALTER TABLE program_courses MODIFY academic_level_id INT NULL DEFAULT NULL, MODIFY recommended_semester_id INT NULL DEFAULT NULL;
END IF;
SELECT 'APPLIED' result;
END//
DELIMITER ;
