// admin/assets/js/admin.js
document.addEventListener('DOMContentLoaded', ()=> {
  const pwToggle = document.querySelector('#togglePw');
  if (pwToggle) {
    pwToggle.addEventListener('click', () => {
      const pw = document.querySelector('#password');
      if (pw.type === 'password') { pw.type = 'text'; pwToggle.textContent = 'Hide'; }
      else { pw.type = 'password'; pwToggle.textContent = 'Show'; }
    });
  }
});
