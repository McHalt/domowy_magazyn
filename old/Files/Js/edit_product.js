document.querySelector('#add_group').addEventListener('click', addGroup);

function addGroup(evt) {
	evt.preventDefault();
	let count = document.querySelectorAll('.groups__group').length;
	let mainDiv = document.createElement('div');
	mainDiv.classList.add('groups__group');
	let select = document.createElement('select');
	select.name = "groups[" + count + "]";
	select.id = "groups[" + count + "]";
	let option = document.createElement('option');
	option.value = '-1';
	option.innerText = 'Wybierz grupę';
	select.appendChild(option);
	Object.values(groups).forEach(function(e) {
		let option = document.createElement('option');
		option.value = e.id;
		option.innerText = e.name
		select.appendChild(option);
	});
	mainDiv.appendChild(select);
	document.querySelector('.groups').appendChild(mainDiv);
}