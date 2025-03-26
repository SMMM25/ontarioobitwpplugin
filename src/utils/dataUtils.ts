
// This file contains data utilities and mock data for the application
// In a real implementation, this would connect to an API

export interface Obituary {
  id: string;
  name: string;
  age?: number;
  dateOfBirth?: string;
  dateOfDeath: string;
  funeralHome: string;
  location: string;
  imageUrl?: string;
  description: string;
  sourceUrl: string;
}

export const mockObituaries: Obituary[] = [
  {
    id: "1",
    name: "Elizabeth Anne Johnson",
    age: 78,
    dateOfBirth: "March 12, 1945",
    dateOfDeath: "May 15, 2023",
    funeralHome: "Smith & Sons Funeral Home",
    location: "Toronto",
    imageUrl: "https://images.unsplash.com/photo-1551966775-a4ddc8df052b?w=800&auto=format&fit=crop&q=60&ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxzZWFyY2h8MTh8fGVsZGVybHklMjB3b21hbnxlbnwwfHwwfHx8MA%3D%3D",
    description: "It is with great sadness that we announce the passing of Elizabeth Anne Johnson, a beloved mother, grandmother, and friend. Elizabeth passed away peacefully at Toronto General Hospital surrounded by her loving family. She was born in Sudbury and moved to Toronto in 1968 where she worked as a dedicated nurse for over 40 years. Elizabeth was known for her kind heart, warm smile, and her famous apple pie that won ribbons at the county fair. She is survived by her three children, James, Sarah, and Robert, and her seven grandchildren who were the light of her life. Elizabeth loved gardening, reading mystery novels, and was an active member of her church community. She will be deeply missed by all who knew her.",
    sourceUrl: "https://example.com/obituary/elizabeth-johnson"
  },
  {
    id: "2",
    name: "Robert Michael Thompson",
    age: 82,
    dateOfDeath: "May 14, 2023",
    funeralHome: "Highland Funeral Home",
    location: "Ottawa",
    imageUrl: "https://images.unsplash.com/photo-1556474835-b0f3ac40d4d1?w=800&auto=format&fit=crop&q=60&ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxzZWFyY2h8MTR8fGVsZGVybHklMjBtYW58ZW58MHx8MHx8fDA%3D",
    description: "Robert Thompson, age 82, passed away on May 14, 2023 at his home in Ottawa after a courageous battle with cancer. Robert was a distinguished professor of Economics at the University of Ottawa for over 35 years. He was a loving husband to his wife of 60 years, Margaret, and a devoted father to his son David and daughter Catherine. Robert was an avid chess player and enjoyed spending weekends at the family cottage in the Gatineau Hills. His passion for teaching and mentoring students has left a lasting impact on generations of economists. Robert will be remembered for his sharp intellect, dry wit, and unwavering integrity. A memorial service will be held at Highland Funeral Home on Saturday, May 20, at 2:00 PM.",
    sourceUrl: "https://example.com/obituary/robert-thompson"
  },
  {
    id: "3",
    name: "Maria Elena Rodriguez",
    age: 65,
    dateOfBirth: "September 8, 1957",
    dateOfDeath: "May 13, 2023",
    funeralHome: "Peaceful Rest Memorial",
    location: "Hamilton",
    imageUrl: "https://images.unsplash.com/photo-1442458370899-ae20e367c5d8?w=800&auto=format&fit=crop&q=60&ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxzZWFyY2h8MTB8fHdvbWFufGVufDB8MXwwfHx8MA%3D%3D",
    description: "Maria Elena Rodriguez, beloved wife, mother, and grandmother, passed away peacefully at home surrounded by her family on May 13, 2023. Born in Mexico City, Maria moved to Canada in 1980 and made Hamilton her home. She was the heart and soul of her family restaurant, 'Maria's Kitchen,' where her authentic recipes and warm hospitality touched countless lives in the community. Maria is survived by her husband Carlos, their three children, and five grandchildren. She was a talented cook, a passionate gardener, and a dedicated volunteer at the local community center. Her zest for life, infectious laughter, and generous spirit will be deeply missed by all who knew her. In lieu of flowers, the family requests donations to the Canadian Cancer Society.",
    sourceUrl: "https://example.com/obituary/maria-rodriguez"
  },
  {
    id: "4",
    name: "James William Wilson",
    age: 71,
    dateOfDeath: "May 12, 2023",
    funeralHome: "Wilson & Sons Funeral Services",
    location: "London, ON",
    description: "James Wilson passed away unexpectedly on May 12, 2023. He was a retired firefighter who served the London Fire Department for 35 years. James was a dedicated husband to his wife Patricia of 45 years, a loving father to his two sons, and a proud grandfather of four. He was an active member of the Veterans' Association and enjoyed fishing, woodworking, and coaching little league baseball. Known for his selflessness and courage, James touched many lives throughout his career and personal life. He will be remembered for his heroism, kindness, and unwavering dedication to his family and community. A celebration of life will be held at Central Park on May 21 at 1:00 PM.",
    sourceUrl: "https://example.com/obituary/james-wilson"
  },
  {
    id: "5",
    name: "Sarah Louise Campbell",
    age: 92,
    dateOfBirth: "January 5, 1931",
    dateOfDeath: "May 11, 2023",
    funeralHome: "Eternal Peace Funeral Home",
    location: "Toronto",
    imageUrl: "https://images.unsplash.com/photo-1450297166380-cabe503887e5?w=800&auto=format&fit=crop&q=60&ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxzZWFyY2h8MTJ8fGVsZGVybHklMjB3b21hbnxlbnwwfDJ8MHx8fDA%3D",
    description: "Sarah Louise Campbell, age 92, passed away peacefully in her sleep on May 11, 2023. Born in Halifax, Sarah moved to Toronto as a young woman where she worked as a librarian for over 40 years. She was predeceased by her husband George and is survived by her daughter Emily, son-in-law Richard, and three grandchildren. Sarah was a passionate advocate for literacy and volunteered for many years teaching adults to read. She was an avid bird watcher, crossword puzzle enthusiast, and world traveler who visited over 30 countries during her retirement years. Sarah's wisdom, grace, and love of learning have inspired generations of her family. A memorial service will be held at the Toronto Public Library, Main Branch, on May 19 at 11:00 AM.",
    sourceUrl: "https://example.com/obituary/sarah-campbell"
  }
];

// Function to fetch obituaries from an API (mock implementation)
export const fetchObituaries = async (): Promise<Obituary[]> => {
  // In a real application, this would be an API call
  return new Promise((resolve) => {
    setTimeout(() => {
      resolve(mockObituaries);
    }, 1000);
  });
};

// Function to filter obituaries
export const filterObituaries = (
  obituaries: Obituary[],
  searchTerm: string = "",
  location: string = ""
): Obituary[] => {
  return obituaries.filter(obit => {
    const matchesSearch = searchTerm === "" || 
      obit.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
      obit.description.toLowerCase().includes(searchTerm.toLowerCase());
    
    const matchesLocation = location === "" || 
      obit.location.toLowerCase().includes(location.toLowerCase());
    
    return matchesSearch && matchesLocation;
  });
};

// Function to get unique locations from obituaries
export const getUniqueLocations = (obituaries: Obituary[]): string[] => {
  return Array.from(
    new Set(obituaries.map(obit => obit.location))
  ).sort();
};
