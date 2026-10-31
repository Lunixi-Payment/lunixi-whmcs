"use strict";
(async function () {
    const error = document.getElementById("lunixi-error");
    try {
        const config = JSON.parse(document.getElementById("lunixi-config").textContent);
        const checkout = await window.Lunixi.checkout.create(config);
        checkout.mount("#lunixi-checkout-form");
    } catch (_) {
        error.hidden = false;
    }
})();
