document.querySelectorAll('.wtp-form[data-wtp-action="retention"]').forEach((form) => {
    const select = form.querySelector('select[name="retention"]');
    const confirmation = form.querySelector('.wtp-retention-confirmation');
    const checkbox = confirmation.querySelector('input[type="checkbox"]');
    const update = () => {
        const rolling = select.value === '90';
        confirmation.hidden = !rolling;
        checkbox.required = rolling;
        if (!rolling) {
            checkbox.checked = false;
        }
    };
    select.addEventListener('change', update);
    update();
});