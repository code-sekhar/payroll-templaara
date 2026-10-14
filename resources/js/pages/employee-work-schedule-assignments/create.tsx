import WorkScheduleAssignmentForm, { WorkScheduleAssignmentFormProps } from './form';

export default function CreateEmployeeWorkScheduleAssignment(props: WorkScheduleAssignmentFormProps) {
    return <WorkScheduleAssignmentForm {...props} mode="create" />;
}
