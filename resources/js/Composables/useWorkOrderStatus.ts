import { usePage } from "@inertiajs/vue3";

export const useWorkOrderStatus = () => {
    const page = usePage();

    const definition = (status: string) =>
        page.props.work_order_statuses.find((item) => item.value === status);

    const label = (status: string): string =>
        definition(status)?.label
        ?? status.replaceAll("_", " ").replace(/\b\w/g, (character) => character.toUpperCase());

    const color = (status: string): string => definition(status)?.status_color ?? "gray";

    const badgeClass = (status: string): string =>
        `fieldops-status-badge--${color(status)}`;

    return { definition, label, color, badgeClass };
};
