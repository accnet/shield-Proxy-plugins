function notification({ title = "", message = "", type = "", duration = 3000 }) {
  const main = document.getElementById("toast");
  if (main) {
    const toast = document.createElement("div");
    const disappear = (duration + 1000).toFixed(2);
    const autoRemoveId = setTimeout(function () {
      main.removeChild(toast);
    }, disappear);

    toast.onclick = (e) => {
      if (e.target.closest(".toast__close")) {
        main.removeChild(toast);
        clearTimeout(autoRemoveId);
      }
    };

    const icons = {
      success: "dashicons-yes-alt",
      info: "dashicons-info",
      warning: "dashicons-warning",
      error: "dashicons-dismiss",
    };

    const icon = icons[type];
    const delay = (duration / 1000).toFixed(2);
    toast.classList.add("toast", `toast--${type}`);
    toast.style.animation = `animation: slideInLeft ease 0.3s, fadeOut linear 1s ${delay}s forwards;`;
    toast.innerHTML = `
              <div class="toast__icon">
              <span class="dashicons ${icon}"></span>
              </div>
              <div class="toast__body">
                  <h3 class="toast__title">${title != "" ? title : type}</h3>
                  <p class="toast__msg">${message}</p>
              </div>
              <div class="toast__close">
              <span class="dashicons dashicons-no"></span>
              </div>
          `;
    main.appendChild(toast);
  }
}
function toastShowError(message = "Đã có lỗi xảy ra") {
  return notification({
    title: "Thất bại",
    message: message,
    type: "error",
  });
}

function toastShowSuccess(message = "Đã được lưu") {
  return notification({
    title: "Thành công",
    message: message,
    type: "success",
  });
}
