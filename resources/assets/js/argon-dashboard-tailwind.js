const baseURL = document.querySelector("meta[name='base-url']").getAttribute("content");
const resourcesURL = baseURL + "/resources/";

const page = (() => {
    const aux = window.location.pathname.split("/");
    if (!aux.includes("pages")) {
        return "dashboard";
    }
    return window.location.pathname.split("/").pop().split(".")[0];
})();

loadStylesheet(resourcesURL + "assets/css/perfect-scrollbar.css");
loadJS(resourcesURL + "assets/js/perfect-scrollbar.js", true);

if (document.querySelector("[slider]")) {
    loadJS(resourcesURL + "assets/js/carousel.js", true);
}

if (document.querySelector("nav [navbar-trigger]")) {
    loadJS(resourcesURL + "assets/js/navbar-collapse.js", true);
}

if (document.querySelector("[data-target='tooltip']")) {
    loadJS(resourcesURL + "assets/js/tooltips.js", true);
    loadStylesheet(resourcesURL + "assets/css/tooltips.css");
}

if (document.querySelector("[nav-pills]")) {
    loadJS(resourcesURL + "assets/js/nav-pills.js", true);
}

if (document.querySelector("[dropdown-trigger]")) {
    loadJS(resourcesURL + "assets/js/dropdown.js", true);
}

if (document.querySelector("[fixed-plugin]")) {
    loadJS(resourcesURL + "assets/js/fixed-plugin.js", true);
}

if (document.querySelector("[navbar-main]") || document.querySelector("[navbar-profile]")) {
    if (document.querySelector("[navbar-main]")) {
        loadJS(resourcesURL + "assets/js/navbar-sticky.js", true);
    }
    if (document.querySelector("aside")) {
        loadJS(resourcesURL + "assets/js/sidenav-burger.js", true);
    }
}

if (document.querySelector("canvas")) {
    loadJS(resourcesURL + "assets/js/charts.js", true);
}

if (document.querySelector(".github-button")) {
    loadJS("https://buttons.github.io/buttons.js", true);
}

function loadJS(FILE_URL, async = true) {
    const dynamicScript = document.createElement("script");
    dynamicScript.src = FILE_URL;
    dynamicScript.type = "text/javascript";
    dynamicScript.async = async;
    document.head.appendChild(dynamicScript);
}

function loadStylesheet(FILE_URL) {
    const dynamicStylesheet = document.createElement("link");
    dynamicStylesheet.href = FILE_URL;
    dynamicStylesheet.type = "text/css";
    dynamicStylesheet.rel = "stylesheet";
    document.head.appendChild(dynamicStylesheet);
}