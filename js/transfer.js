if ('serviceWorker' in window.navigator){
    window.addEventListener('load',()=>{
	window.navigator.serviceWorker.register('/velox/worker.js');
    });
}