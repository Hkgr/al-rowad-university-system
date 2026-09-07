<?php

namespace App\Support;

enum RegistrationMaterializationContext
{
    case STUDENT_WINDOW;
    case ADVISOR_APPROVAL;
    case EXAM_MANUAL_RECORDING;
}
