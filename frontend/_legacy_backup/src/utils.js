import router from "@/router.js";
import config from "@/config.json";
import axios from "axios";

export function notify (text, error) {
    let notifyContainer = document.querySelector(".popup_notification_container");
    let div = document.createElement("div");

    if (error) {
        div.innerHTML = `<div class="popup_notification error">
                                    <i class="fa-solid fa-triangle-exclamation"></i>
                                    <div>
                                        ${text}
                                    </div>
                                </div>`
    } else {
        div.innerHTML = `<div class="popup_notification success">
                                    <i class="fa-solid fa-circle-check"></i>
                                    <div>
                                        ${text}
                                    </div>
                                </div>`
    }
    notifyContainer.appendChild(div);

    let height = div.querySelector(".popup_notification").getBoundingClientRect().height + 10;
    div.style.visibility = "visible";
    div.style.transform = `translateY(-${height}px)`;

    requestAnimationFrame(() => {
        div.style.transition = "0.2s";
        div.style.transform = "";
        div.style.height = height + "px";
    });

    setTimeout(() => {
        div.style.opacity = '0';
        setTimeout (() => {
            div.remove();
        }, 200);
    }, 5000);
}

export function toLink (query, id = null, type = null, needback = 1) {
    document.body.style.overflow = "";

    if (id) router.push({ query: { s: query, id: id, type: type, needback: needback }});
    else router.push({ query: { s: query, needback: needback }});

    let overlay = document.querySelector(".image-overlay");
    if (overlay) overlay.remove();
}

export function levenshtein(a, b) {
    const matrix = [];

    for(let i = 0; i <= b.length; i++){
        matrix[i] = [i];
    }
    for(let j = 0; j <= a.length; j++){
        matrix[0][j] = j;
    }
    for(let i = 1; i <= b.length; i++){
        for(let j = 1; j <= a.length; j++){
            if(b.charAt(i-1) === a.charAt(j-1)){
                matrix[i][j] = matrix[i-1][j-1];
            } else {
                matrix[i][j] = Math.min(
                    matrix[i-1][j-1] + 1, // заменить
                    matrix[i][j-1] + 1,   // вставить
                    matrix[i-1][j] + 1    // удалить
                );
            }
        }
    }
    return matrix[b.length][a.length];
}

export function utcToLocalTime(utcString) {
    const date = new Date(utcString);

    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');

    return `${hours}:${minutes}`;
}

export function showOverlay (cl) {
    document.body.style.overflow = "hidden";

    let el = document.querySelector(`.overlay.${cl}`);
    el.style.display = "";
    el.style.transform = "translateY(100%)";

    let background = document.querySelector(`.background.${cl}`);
    background.style.display = "";
    background.style.opacity = 0;

    requestAnimationFrame(() => {
        el.style.transform = "";
        background.style.opacity = "";
    });
}
export function hideOverlay (cl) {
    let el = document.querySelector(`.overlay.${cl}`);
    el.style.transform = "translateY(100%)";

    let background = document.querySelector(`.background.${cl}`);
    background.style.opacity = 0;

    setTimeout(() => {
        el.style.transform = "";
        background.style.opacity = "";
        background.style.display = "none";

        el.style.display = "none";
        document.body.style.overflow = "";
    }, 200);
}

export async function openList (event) {
    let select = event.target.closest(".store_input_select_container");
    document.querySelectorAll(".store_input_select_list").forEach(el => {
        if (el !== select.querySelector(".store_input_select_list"))
            el.classList.remove("active");
    })

    select.querySelector(".store_input_select_list").classList.toggle("active");
}
export async function hideList (event) {
    let el = event.target.closest(".store_input_select_container");
    el.querySelector(".store_input_select_list").classList.remove("active");
}

export function favourite (action, type, id, isLoading, user) {
    if (isLoading.status) return;

    isLoading.status = true;
    axios.post(config.backend + "favourite" + (action ? '' : '/delete'), {
        initData: window.Telegram.WebApp.initData,
        type: type,
        object_id: id,
    }).then((response) => {
        if (action) {
            notify("Успешно добавлено в избранное!");
            if (!user || !user.favourites || !Array.isArray(user.favourites[type])) user.favourites[type] = [];
            user.favourites[type].push(id);
        } else {
            notify("Успешно удалено из избранного!");
            user.favourites[type] = user.favourites[type].filter(el => el !== id);
        }
        this.$store.dispatch("updateUser", user);
    }).catch((error) => {
        if (error.response)
            notify(error.message, 1);
    }).finally(() => {
        isLoading.status = false;
    })
}

export function toLocalSimpleISO(date) {
    const pad = n => String(n).padStart(2, "0")
    return [
            date.getFullYear(),
            pad(date.getMonth() + 1),
            pad(date.getDate())
        ].join("-") + "T" +
        [
            pad(date.getHours()),
            pad(date.getMinutes()),
            pad(date.getSeconds())
        ].join(":") +
        "." +
        String(date.getMilliseconds()).padStart(3, "0")
}

export function complain (type, id) {
    let user = this.$store.state.user;

    let status = true;

    let string = prompt("Введите причину жалобы:");
    if (!string) return notify ("Пустая причина жалобы!", 1);

    axios.post(config.backend + "complain", {
        initData: window.Telegram.WebApp.initData,
        type: type,
        object_id: id,
        reason: string,
    }).then((response) => {
        status = true;
        notify ("Жалоба успешно отправлена!");
    }).catch(() => {
        status = false;
    });

    return status;
}

export function endLoading (cl = "loading") {
    let loading = document.querySelector("." + cl);
    loading.style.opacity = 0;
    setTimeout(() => {
        loading.style.display = "none";
    }, 400);
}

export function startLoading (cl = "loading") {
    let loading = document.querySelector("." + cl);
    loading.style.display = "";
    requestAnimationFrame(() => {
        loading.style.opacity = "1";
    })
}

export function copy(text) {
    if (navigator.clipboard && window.isSecureContext) {
        return navigator.clipboard.writeText(text)
            .then(() => notify("Успешно скопировано!"))
            .catch(() => notify("Устройство не поддерживает копирование!", 1));
    } else {
        let textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();
        try {
            document.execCommand('copy');
            document.body.removeChild(textarea);
            return notify("Успешно скопировано!");
        } catch (err) {
            document.body.removeChild(textarea);
            return notify("Устройство не поддерживает копирование!", 1);
        }
    }
}

export function timestampToDate(timestamp) {
    if (!timestamp) {
        return "";
    }
    const date = new Date(timestamp);

    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0'); // Месяцы 0-11
    const year = date.getFullYear();

    return `${day}.${month}.${year}`;
}

export function openOverlay(overlay, background = null) {
    document.body.style.overflow = "hidden";
    let elem = document.querySelector('.' + overlay);
    elem.style.display = "";
    if (background) document.querySelector('.' + background).style.display = "";
    requestAnimationFrame(() => {
        elem.style.transform = "translateY(0)";
        if (background) document.querySelector('.' + background).style.opacity = "1";
    });

    window.Telegram.WebApp.BackButton.offClick(window.backByQueryFunction);
    window.Telegram.WebApp.BackButton.onClick(closeAllOverlays);
    window.Telegram.WebApp.BackButton.show();
}

export function closeOverlay(overlay, background = null) {
    document.body.style.overflow = "";
    let elem = document.querySelector('.' + overlay);
    elem.style.transform = "";
    if (background) document.querySelector('.' + background).style.opacity = "";
    elem.addEventListener('transitionend', function() {
        elem.style.display = "none";
        if (background) document.querySelector('.' + background).style.display = "none";
    }, {once: true});

    window.Telegram.WebApp.BackButton.offClick(closeAllOverlays);
    window.Telegram.WebApp.BackButton.onClick(window.backByQueryFunction);
}

export function closeAllOverlays () {
    document.body.style.overflow = "";
    document.querySelectorAll(".overlay").forEach(el => {
        if (el.style.display !== "none") {
            el.style.transform = "";
            el.addEventListener('transitionend', function() {
                el.style.display = "none";
            }, {once: true});
        }
    });
    document.querySelectorAll(".background").forEach(el => {
        if (el.style.display !== "none") {
            el.style.opacity = "";
            el.addEventListener('transitionend', function() {
                el.style.display = "none";
            }, {once: true});
        }
    });

    window.Telegram.WebApp.BackButton.offClick(closeAllOverlays);
    window.Telegram.WebApp.BackButton.onClick(window.backByQueryFunction);
}

export function showSuccess () {
    let elem = document.querySelector('.successComponent');
    elem.style.display = "";
    requestAnimationFrame(() => {
        elem.style.opacity = "1";
    });
}

export function formatPrice(number, decimals = 0) {
    let parts = number.toFixed(decimals).split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return decimals > 0 ? parts.join('.') : parts[0];
}

export function getFileIcon (filename) {
    if (!filename) return;
    const formatIcons = {
        pdf: 'fa-file-pdf',
        svg: 'fa-file-image',
        ppt: 'fa-file-powerpoint',
        pptx: 'fa-file-powerpoint',
        doc: 'fa-file-word',
        docx: 'fa-file-word',
        xls: 'fa-file-excel',
        xlsx: 'fa-file-excel',
        png: 'fa-file-image',
        jpg: 'fa-file-image',
        jpeg: 'fa-file-image',
        txt: 'fa-file-alt',
        zip: 'fa-file-archive',
        rar: 'fa-file-archive',
        mp3: 'fa-file-audio',
        default: 'fa-file'
    };

    const ext = filename.split('.').pop().toLowerCase();
    return formatIcons[ext] || formatIcons.default;
}

export function showPopup (popup, background) {
    let popupEl = document.querySelector('.' + popup);
    let backgroundEl = document.querySelector('.' + background);

    popupEl.style.display = "";
    popupEl.style.opacity = "0";
    backgroundEl.style.display = "";
    backgroundEl.style.opacity = "0";
    requestAnimationFrame(() => {
        popupEl.style.opacity = "1";
        backgroundEl.style.opacity = "1";
    });

    backgroundEl.addEventListener('click', () => {
        backgroundEl.style.opacity = "0";
        popupEl.style.opacity = "0";
        popupEl.addEventListener('transitionend', () => {
            popupEl.style.display = "none";
            backgroundEl.style.display = "none";
        }, {once: true});
    });
}

export function getPrevWithClass(elem, className) {
    let prev = elem.previousElementSibling;
    while (prev) {
        if (prev.classList && prev.classList.contains(className)) {
            return prev;
        }
        prev = prev.previousElementSibling;
    }
    return null;
}

export function checkRules (rules) {
    let isError = false;
    for (let rule of rules) {
        let el = document.querySelector("#" + rule[0]);
        el.style.transition = "all 0.2s";
        el.style.outline = "";
    }

    for (let rule of rules) {
        if (rule[1]) {
            isError = true;

            let el = document.querySelector("#" + rule[0]);
            el.style.transition = "all 0.2s";
            el.style.outline = "1px solid #f44336";

            notify(rule[2], 1);
        }
    }

    return isError;
}

export function deepParse(obj) {
    if (typeof obj === 'string') {
        try {
            let parsed = JSON.parse(obj);
            return deepParse(parsed); // рекурсивно обрабатываем ещё раз
        } catch (e) {
            return obj; // если не парсится — вернуть как есть
        }
    } else if (Array.isArray(obj)) {
        return obj.map(deepParse);
    } else if (typeof obj === 'object' && obj !== null) {
        let res = {};
        for (let key in obj) {
            res[key] = deepParse(obj[key]);
        }
        return res;
    }
    return obj;
}

export function getDate (date) {
    if (!date) return "";

    let dat = new Date(date);
    const months = ["янв", "фев", "мар", "апр", "май", "июн", "июл", "авг", "сен", "окт", "ноя", "дек"];

    return `${dat.getDate()} ${months[dat.getMonth()]} ${dat.getHours().toString().padStart(2, '0')}:${dat.getMinutes().toString().padStart(2, '0')}`
}

export let levels = {
    '1-4': '1-4 класс',
        '5-9': '5-9 класс',
        '10-11': '10-11 класс',
        'student': 'Студент',
        'self': 'Для себя'
}

export function getMoscowDateTimeString() {
    const now = new Date();
    const options = {
        timeZone: 'Europe/Moscow',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false
    };
    const parts = new Intl.DateTimeFormat('en-GB', options)
        .formatToParts(now)
        .reduce((acc, part) => {
            if (part.type !== 'literal') acc[part.type] = part.value;
            return acc;
        }, {});
    return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}:${parts.second}`;
}
