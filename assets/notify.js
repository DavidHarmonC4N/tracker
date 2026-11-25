'use strict';
// Copy button for the topic on a radio page. Without JavaScript the button stays hidden and the topic can still be selected.
(function () {
  var button = document.getElementById('notify-copy'), topic = document.getElementById('notify-topic'), status = document.getElementById('notify-status');
  if (!button || !topic || !navigator.clipboard) return;
  button.hidden = false;
  button.addEventListener('click', function () {
    navigator.clipboard.writeText(topic.textContent).then(
      function () { status.textContent = 'Copied'; },
      function () { status.textContent = 'Could not copy. Select the topic instead.'; });
  });
})();
