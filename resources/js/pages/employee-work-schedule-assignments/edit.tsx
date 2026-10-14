import WorkScheduleAssignmentForm, { WorkScheduleAssignmentFormProps } from './form';

export default function EditEmployeeWorkScheduleAssignment(props: WorkScheduleAssignmentFormProps) {
    return <WorkScheduleAssignmentForm {...props} mode="edit" />;
}
